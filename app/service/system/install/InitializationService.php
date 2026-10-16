<?php
declare(strict_types=1);

namespace app\service\system\install;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\repository\payment\config\PaymentAppKeyRepository;
use app\repository\payment\config\PaymentAppRepository;
use app\repository\system\AdminOperationLogRepository;
use app\repository\system\AdminUserRepository;
use app\repository\system\InitializationRepository;
use app\service\payment\config\PaymentAppCredentialService;
use think\App;

/**
 * 已迁移空业务库的首次初始化；命名锁与迁移共用，管理员/首个应用/凭证/标记/审计在一个业务事务提交。
 * 不执行 DDL、不覆盖既有账号、不返回 KEY。凭证服务延迟至真正初始化时装配。
 */
final class InitializationService extends BaseService
{
    public function __construct(
        private readonly App $app,
        private readonly InitializationRepository $state,
        private readonly AdminUserRepository $admins,
        private readonly PaymentAppRepository $apps,
        private readonly PaymentAppKeyRepository $versions,
        private readonly AdminOperationLogRepository $logs,
        private readonly Clock $clock,
    ) {}

    /** @return array<string,mixed> 只读初始化状态；schema_pending / empty / initialized / nonempty / inconsistent 均不包含秘密正文。 */
    public function status(): array
    {
        return $this->withSchemaLock(function (
            MigrationService $migrations,
            array $inspection,
        ): array {
            if (!$inspection['installed'] || $inspection['pending_steps'] !== 0) {
                return ['phase' => 'schema_pending', 'schema' => $inspection];
            }
            $tables = $migrations->managedTables($this->directory());
            return $this->transaction(function () use ($tables, $inspection): array {
                $occupied = $this->state->occupiedTables($tables);
                $marker = $this->state->marker();
                $dictionaryReady = $this->state->dictionaryReady();
                $phase = $occupied === [] && $dictionaryReady ? 'empty' : 'nonempty';
                if ($marker !== null) {
                    $value = $marker['value_json'];
                    $admin = $this->admins->singleton();
                    $application =
                        isset($value['app_id']) && is_string($value['app_id'])
                            ? $this->apps->find($value['app_id'])
                            : null;
                    $active =
                        $application === null || $application['active_key_id'] === null
                            ? null
                            : $this->versions->version(
                                $application['id'],
                                $application['active_key_id'],
                            );
                    $valid =
                        $dictionaryReady &&
                        is_array($value) &&
                        ($value['schema_version'] ?? null) === 2 &&
                        $admin !== null &&
                        $application !== null &&
                        $active !== null &&
                        ($value['admin_id'] ?? null) === $admin['id'] &&
                        ($value['app_id'] ?? null) === $application['id'] &&
                        is_string($value['credential_id'] ?? null) &&
                        $this->versions->version($application['id'], $value['credential_id']) !==
                            null;
                    $phase = $valid ? 'initialized' : 'inconsistent';
                }
                return ['phase' => $phase, 'schema' => $inspection, 'occupied_tables' => $occupied];
            });
        });
    }

    /**
     * 输入由 InitializationValidator 完整核对；所有业务表必须为空（迁移建立的支付字典除外）。
     * 密码哈希在业务事务外完成，事务内创建应用凭证并保存数据库记录。
     *
     * @return array{admin_id:string,app_id:string,pid:string,credential_id:string,initialized_at:string} 不输出密码或 API KEY，安装后登录后台受控查看。
     */
    public function initialize(
        string $username,
        string $password,
        string $appName,
        ?string $pid = null,
    ): array {
        return $this->withSchemaLock(function (
            MigrationService $migrations,
            array $inspection,
        ) use ($username, $password, $appName, $pid): array {
            if (!$inspection['installed'] || $inspection['pending_steps'] !== 0) {
                throw new BusinessException(
                    '请先独立完成 system:migrate，再初始化业务数据',
                    ApiResponseConstant::CONFLICT,
                );
            }
            $tables = $migrations->managedTables($this->directory());
            if ($this->state->occupiedTables($tables) !== [] || !$this->state->dictionaryReady()) {
                throw new BusinessException(
                    '目标库已有业务/配置数据或字典未就绪，拒绝初始化及覆盖',
                    ApiResponseConstant::CONFLICT,
                );
            }
            // 真正初始化时才装配凭证服务；只读状态不生成或重置应用凭证。
            $credentials = $this->app->make(PaymentAppCredentialService::class, [], true);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $number = $pid ?? (string) random_int(100000000, 999999999);
            return $this->transaction(function () use (
                $tables,
                $username,
                $hash,
                $appName,
                $number,
                $credentials,
            ): array {
                // 当前读再次保护非空边界；删除安装标记不能绕过实际管理员、订单、凭证或审计记录。
                if ($this->state->occupiedTables($tables, true) !== []) {
                    throw new BusinessException(
                        '业务库已被其他初始化或写入占用',
                        ApiResponseConstant::CONFLICT,
                    );
                }
                $adminId = $this->admins->create($username, $hash);
                $appId = $this->apps->create($number, $appName, []);
                $credentialId = $credentials->rotateLocked($appId, null)['id'];
                $this->app
                    ->make(\app\service\payment\routing\DefaultRoutingService::class)
                    ->applyToApp($appId);
                $now = $this->clock->now()->format('Y-m-d H:i:s');
                $references = [
                    'admin_id' => $adminId,
                    'app_id' => $appId,
                    'credential_id' => $credentialId,
                    'initialized_at' => $now,
                ];
                $this->state->record($references);
                $this->logs->record([
                    'admin_user_id' => $adminId,
                    'request_id' => bin2hex(random_bytes(16)),
                    'action' => 'system.initialize',
                    'target_type' => 'system_installation',
                    'target_id' => $appId,
                    'result_code' => 'created',
                    'client_ip' => null,
                    'summary_json' => json_encode(
                        ['origin' => 'cli', 'credential_version' => 1],
                        JSON_THROW_ON_ERROR,
                    ),
                ]);
                return $references + ['pid' => $number];
            });
        });
    }

    /**
     * 同连接取得迁移命名锁后，inspect 可重入并只释放自己的那次取得；初始化持锁直到业务事务结束。
     * 额外核对实际锁归属，不能在不支持重入的数据库实现上误以为仍受保护。
     *
     * @template T
     * @param callable(MigrationService,array):T $operation
     * @return T
     */
    private function withSchemaLock(callable $operation): mixed
    {
        // CLI 与受安装锁/同源/CSRF 保护的网页入口共用此服务；入口授权不混入数据库事务。
        // 直接取当前 ORM 连接，避免容器缓存的 PDO 指向旧连接；命名锁、检查与业务事务必须属于同一连接。
        $pdo = $this->app->db->connect()->connect();
        if ($pdo->inTransaction()) {
            throw new \LogicException('初始化不能嵌套在已有事务中');
        }
        $lock = MigrationService::databaseLockName(
            (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        );
        $claim = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $claim->execute([$lock]);
        if ((int) $claim->fetchColumn() !== 1) {
            throw new BusinessException(
                '迁移或初始化正在执行，请完成后再核对状态',
                ApiResponseConstant::CONFLICT,
            );
        }
        try {
            // 明确使用同一 PDO，不能复用绑定到上一测试库或旧连接的迁移服务实例。
            $migrations = new MigrationService($pdo);
            $inspection = $migrations->inspect($this->directory());
            $owner = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            $owner->execute([$lock]);
            if ((int) $owner->fetchColumn() !== 1) {
                throw new \RuntimeException('初始化检查后迁移锁归属丢失');
            }
            return $operation($migrations, $inspection);
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    private function directory(): string
    {
        return $this->app->getRootPath() . 'database/migrations';
    }
}
