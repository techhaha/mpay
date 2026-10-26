<?php
declare(strict_types=1);

namespace app\service\system\install;

use app\service\payment\plugin\PaymentPluginFactoryService;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * 执行新装基线中的最终建表定义，保存 pending/complete 并支持中断续跑。
 *
 * 数据库负责执行字段、索引和外键定义；本服务核对步骤摘要、表归属、基本表类型。
 * 只用于空库新装与同一基线续装，不接管历史开发库，也不执行自动修表或回滚。
 */
final class MigrationService
{
    public const MINIMUM_MYSQL = '5.7.9';

    public function __construct(private readonly PDO $pdo) {}

    /** 原生 JSON/生成列使用 MySQL 5.7.9 及以上；MariaDB 不在当前支持范围。 */
    public static function assertVersion(string $version): void
    {
        if (
            stripos($version, 'mariadb') !== false ||
            !preg_match('/^(\\d+\\.\\d+\\.\\d+)(?:[-+].*)?$/D', $version, $match) ||
            version_compare($match[1], self::MINIMUM_MYSQL, '<')
        ) {
            throw new RuntimeException('需要 MySQL ' . self::MINIMUM_MYSQL . ' 或更高版本');
        }
    }

    /**
     * 只读查看进度并让 MySQL 解析待建 SQL，不补写摘要/complete 或初始化字典。
     *
     * @return array{installed:bool,total_steps:int,completed_steps:int,pending_steps:int,versions:list<string>}
     * @throws RuntimeException 未知表、丢表、步骤被改动、进度不连续或已有迁移正在运行。
     */
    public function inspect(string $directory): array
    {
        $steps = $this->loadPlan($directory);
        return $this->locked(function () use ($steps): array {
            $state = $this->state($steps);
            $this->checkSyntax($steps, $state['tables']);
            $completed = count(
                array_filter(
                    $state['records'],
                    static fn(array $record): bool => $record['state'] === 'complete',
                ),
            );
            return [
                'installed' => isset($state['tables']['_mpay_migration_step']),
                'total_steps' => count($steps),
                'completed_steps' => $completed,
                'pending_steps' => count($steps) - $completed,
                'versions' => array_values(
                    array_unique(
                        array_map(static fn(MigrationStep $step): string => $step->version, $steps),
                    ),
                ),
            ];
        }, 0);
    }

    /**
     * DDL 不能整体回滚；每步先记录意图，成功后记录完成，中断时保留已经建好的表。
     *
     * @param string $directory 程序内固定的迁移目录，不接受 HTTP 或 CLI 自选路径。
     * @return list<string> 全部建表步骤完成的版本。
     * @throws RuntimeException 数据库归属或迁移记录不符，停止而不删除原数据。
     * @throws PDOException 建表或写进度失败，保留现场供同一版本继续。
     */
    public function migrate(string $directory): array
    {
        $steps = $this->loadPlan($directory);
        return $this->locked(function () use ($steps, $directory): array {
            $state = $this->state($steps);
            $this->checkSyntax($steps, $state['tables']);
            if (!isset($state['tables']['_mpay_migration_step'])) {
                // state 已确认整个库为空，不能在其他应用的数据库中补建迁移账本。
                $this->pdo->exec(
                    <<<'SQL'
                    CREATE TABLE _mpay_migration_step (
                      `version` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '迁移版本文件名，不含.php扩展名',
                      `step` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '版本内步骤名，与version共同组成主键',
                      `checksum` char(64) COLLATE utf8mb4_bin NOT NULL COMMENT '建表SQL原文的SHA-256摘要；已登记步骤不得被静默替换',
                      `state` varchar(16) COLLATE utf8mb4_bin NOT NULL COMMENT '迁移步骤状态：pending已登记待完成，complete执行完成',
                      `completed_at` datetime DEFAULT NULL COMMENT '步骤确认完成时间，上海时区；pending时为NULL',
                      PRIMARY KEY (`version`,`step`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='MPAY安装迁移账本，记录步骤摘要、完成状态及中断续跑位置'
                    SQL
                    ,
                );
            }
            foreach ($steps as $step) {
                $record = $state['records'][$step->identity()] ?? null;
                // 已完成步骤只核对摘要和表归属，不重复建表或初始化字典。
                if (($record['state'] ?? null) === 'complete') {
                    continue;
                }
                if ($record === null) {
                    $this->pdo
                        ->prepare(
                            "INSERT INTO _mpay_migration_step (version,step,checksum,state) VALUES (?,?,?,'pending')",
                        )
                        ->execute([$step->version, $step->name, $step->checksum]);
                } elseif (!hash_equals($step->checksum, $record['checksum'])) {
                    // state 仅允许表尚未创建的 pending 步骤修正 SQL；真正执行前在迁移锁内更新原记录。
                    $update = $this->pdo->prepare(
                        "UPDATE _mpay_migration_step SET checksum=? WHERE version=? AND step=? AND state='pending' AND checksum=?",
                    );
                    $update->execute([
                        $step->checksum,
                        $step->version,
                        $step->name,
                        $record['checksum'],
                    ]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('待建步骤记录已变化，请重新检查安装进度');
                    }
                }
                // 同摘要 pending 且表已存在，表示建表完成但确认中断；不重复 CREATE，也不清空该表。
                // 只有 state 已确认属于本次迁移的表可走此分支；同名未知表在执行前即被拒绝。
                if (!isset($state['tables'][$step->table])) {
                    $this->pdo->exec($step->sql);
                    $this->assertTable($step->table, $this->tables()[$step->table] ?? null);
                }
                // 初装字典先于该步 complete 写入；已安装后不重新添加管理员删除的支付方式。
                if ($step->table === 'ma_payment_type') {
                    $types = require dirname($directory) . '/seeds/payment_types.php';
                    $insert = $this->pdo->prepare(
                        'INSERT INTO ma_payment_type (code,name,enabled) VALUES (?,?,?) ON DUPLICATE KEY UPDATE code=VALUES(code)',
                    );
                    foreach ($types as $type) {
                        $insert->execute([$type['code'], $type['name'], $type['enabled']]);
                    }
                }
                if ($step->table === 'ma_payment_plugin') {
                    // 仅初次安装初始化已交付插件；元信息读取不联网，写入仍严格使用本次迁移的 PDO。
                    $factory = app()->make(PaymentPluginFactoryService::class);
                    $insert = $this->pdo->prepare(
                        'INSERT INTO ma_payment_plugin (code,name,version,pay_types,class_name,config_schema,description,enabled) VALUES (?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE code=VALUES(code)',
                    );
                    foreach ($factory->codes() as $code) {
                        $definition = $factory->definition($code);
                        $insert->execute([
                            $code,
                            $definition->name,
                            $definition->version,
                            json_encode($definition->payTypes, JSON_THROW_ON_ERROR),
                            $definition->className,
                            json_encode(
                                $definition->configSchema,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                            ),
                            $definition->description,
                        ]);
                    }
                }
                $this->pdo
                    ->prepare(
                        "UPDATE _mpay_migration_step SET state='complete',completed_at=COALESCE(completed_at,NOW()) WHERE version=? AND step=?",
                    )
                    ->execute([$step->version, $step->name]);
            }
            return array_values(
                array_unique(
                    array_map(static fn(MigrationStep $step): string => $step->version, $steps),
                ),
            );
        }, 10);
    }

    /** @return list<string> 固定建表集合，供安装服务检查已有业务数据，不连接数据库。 */
    public function managedTables(string $directory): array
    {
        return array_map(
            static fn(MigrationStep $step): string => $step->table,
            $this->loadPlan($directory),
        );
    }

    /** 初始化和迁移共用连接级命名锁；重入时每次取得都对应一次释放。 */
    public static function databaseLockName(string $database): string
    {
        if ($database === '') {
            throw new InvalidArgumentException('数据库锁缺少目标库');
        }
        return 'mpay:migrate:' . substr(hash('sha256', $database), 0, 40);
    }

    /**
     * 文件顺序、文件内声明顺序决定建表次序；重复表名在写入数据库前拒绝。
     * 文件内按外键依赖排列；不维护旧结构到新结构的升级分支。
     *
     * @return list<MigrationStep>
     */
    private function loadPlan(string $directory): array
    {
        $files = glob(rtrim($directory, '/\\\\') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $steps = [];
        $tables = [];
        foreach ($files as $file) {
            $definitions = (static fn(string $path): mixed => require $path)($file);
            if (!is_array($definitions) || $definitions === []) {
                throw new RuntimeException('迁移文件未返回建表定义');
            }
            foreach ($definitions as $name => $definition) {
                if (!is_string($name)) {
                    throw new RuntimeException('迁移步骤缺少表名');
                }
                $step = MigrationStep::fromDefinition(
                    pathinfo($file, PATHINFO_FILENAME),
                    $name,
                    $definition,
                );
                if (isset($tables[$step->table])) {
                    throw new RuntimeException('迁移存在重复建表定义：' . $step->table);
                }
                $steps[] = $step;
                $tables[$step->table] = true;
            }
        }
        if ($steps === []) {
            throw new RuntimeException('没有可执行的迁移文件');
        }
        return $steps;
    }

    /**
     * 以账本证明建表归属：无记录的非空库、陌生表和已完成但丢失的表均拒绝续跑。
     * 已建表的 SQL 摘要不可变；仅 pending 且表未创建时允许修正失败的 SQL，读取时不更新账本。
     * pending 可对应尚未建表或已建普通 InnoDB 表；不能用这里的通过结果宣称字段与索引从未被人工修改。
     *
     * @param list<MigrationStep> $steps
     * @return array{tables:array<string,array>,records:array<string,array>}
     */
    private function state(array $steps): array
    {
        $tables = $this->tables();
        if (!isset($tables['_mpay_migration_step'])) {
            if ($tables !== []) {
                throw new RuntimeException('拒绝接管无迁移记录的非空数据库');
            }
            return ['tables' => [], 'records' => []];
        }
        $this->assertTable('_mpay_migration_step', $tables['_mpay_migration_step']);
        $expected = [];
        $byTable = [];
        foreach ($steps as $step) {
            $expected[$step->identity()] = $step;
            $byTable[$step->table] = $step->identity();
        }
        if (
            array_diff(array_keys($tables), [...array_keys($byTable), '_mpay_migration_step']) !==
            []
        ) {
            throw new RuntimeException('数据库含未声明的表，拒绝接管');
        }
        $records = [];
        foreach (
            $this->pdo
                ->query('SELECT version,step,checksum,state FROM _mpay_migration_step')
                ->fetchAll(PDO::FETCH_ASSOC)
            as $record
        ) {
            $identity = $record['version'] . ':' . $record['step'];
            // 不同迁移链不能仅修改摘要冒充本版新装；明确区分版本不符与记录损坏。
            if (!isset($expected[$identity])) {
                throw new RuntimeException(
                    '数据库迁移记录不属于当前安装基线，请核对数据库与程序版本；禁止修改摘要或重新安装覆盖',
                );
            }
            if (
                !preg_match('/^[a-f0-9]{64}$/D', $record['checksum']) ||
                !in_array($record['state'], ['pending', 'complete'], true)
            ) {
                throw new RuntimeException('数据库迁移记录的摘要格式或进度状态无效，请核对原始记录');
            }
            $step = $expected[$identity];
            if (
                !hash_equals($step->checksum, $record['checksum']) &&
                ($record['state'] !== 'pending' || isset($tables[$step->table]))
            ) {
                throw new RuntimeException('已创建表的迁移 SQL 摘要已改变，拒绝覆盖');
            }
            $records[$identity] = $record;
        }
        $unfinished = false;
        foreach ($steps as $step) {
            $record = $records[$step->identity()] ?? null;
            if ($record !== null && $unfinished) {
                throw new RuntimeException('迁移进度不连续，需先核对中断步骤');
            }
            if (isset($tables[$step->table])) {
                if ($record === null) {
                    throw new RuntimeException('存在未登记的同名表：' . $step->table);
                }
                $this->assertTable($step->table, $tables[$step->table]);
            } elseif (($record['state'] ?? null) === 'complete') {
                throw new RuntimeException('已完成迁移的表缺失，拒绝自动重建：' . $step->table);
            }
            $unfinished = $unfinished || ($record['state'] ?? null) !== 'complete';
        }
        return ['tables' => $tables, 'records' => $records];
    }

    /** @return array<string,array{TABLE_TYPE:string,ENGINE:?string}> 只读取表类型和引擎，不读取字段、索引或业务数据。 */
    private function tables(): array
    {
        $result = [];
        foreach (
            $this->pdo
                ->query(
                    'SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()',
                )
                ->fetchAll(PDO::FETCH_ASSOC)
            as $row
        ) {
            $result[$row['TABLE_NAME']] = [
                'TABLE_TYPE' => $row['TABLE_TYPE'],
                'ENGINE' => $row['ENGINE'],
            ];
        }
        return $result;
    }

    /** 视图和非事务引擎不能作为业务表；完整字段和约束由受控建表 SQL 交给 MySQL 执行。 */
    private function assertTable(string $name, ?array $table): void
    {
        if (
            ($table['TABLE_TYPE'] ?? null) !== 'BASE TABLE' ||
            strcasecmp($table['ENGINE'] ?? '', 'InnoDB') !== 0
        ) {
            throw new RuntimeException('迁移需要普通 InnoDB 表：' . $name);
        }
    }

    /**
     * 建表前由 MySQL 原生预处理解析全部待建 SQL，不执行 DDL，也不自行解析 SQL。
     * 仅检查语法，权限和外键等执行条件仍以实际建表结果为准；恢复 PDO 设置供后续 ORM 使用。
     *
     * @param list<MigrationStep> $steps
     * @param array<string,array> $tables 已核对归属的现有表集合。
     */
    private function checkSyntax(array $steps, array $tables): void
    {
        $emulated = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        try {
            foreach ($steps as $step) {
                if (isset($tables[$step->table])) {
                    continue;
                }
                $statement = $this->pdo->prepare($step->sql);
                $statement = null;
            }
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
        }
    }

    /**
     * @template T
     * @param callable():T $operation 不在数据库事务中执行 DDL；命名锁在异常时也释放。
     * @return T
     */
    private function locked(callable $operation, int $wait): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('迁移不能置于业务事务中');
        }
        self::assertVersion((string) $this->pdo->query('SELECT VERSION()')->fetchColumn());
        $lock = self::databaseLockName(
            (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(),
        );
        $claim = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $claim->execute([$lock, $wait]);
        if ((int) $claim->fetchColumn() !== 1) {
            throw new RuntimeException('迁移或初始化正在执行，请稍后重试');
        }
        try {
            return $operation();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
}
