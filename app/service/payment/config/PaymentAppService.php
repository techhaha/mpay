<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\service\system\audit\ConfigurationAuditService;
use app\service\system\audit\ConfigurationActionContext;
use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\util\Clock;
use app\common\util\HttpTargetPolicy;
use app\exception\BusinessException;
use app\model\payment\PaymentApp;
use app\repository\payment\config\PaymentAppRepository;
use app\service\payment\epay\EpayV1Signer;
use app\service\system\access\AdminAuthService;

/** 应用管理用例；写操作与审计共用事务，秘密展示/重置先复核密码，常规列表不携带 KEY。 */
final class PaymentAppService extends BaseService
{
    public function __construct(private readonly PaymentAppRepository $apps, private readonly PaymentAppCredentialService $credentials,
        private readonly ConfigurationAuditService $audit, private readonly AdminAuthService $auth,
        private readonly EpayV1Signer $signer, private readonly Clock $clock,
        private readonly \app\service\payment\routing\DefaultRoutingService $defaults) {}

    /** @return list<array<string,mixed>> 公开管理投影，不包含密文或密钥指纹。 */
    public function listing(): array
    {
        return $this->transaction(function (): array {
            return array_map(function (PaymentApp $app): array {
                $key = $this->credentials->active($app);
                return ['id' => $app['id'], 'pid' => $app['pid'], 'name' => $app['name'], 'enabled' => (int) $app['status'] === 1,
                    'use_default_route' => (int) $app['use_default_route'] === 1,
                    'revision' => (int) $app['revision'], 'allowed_hosts' => $app['allowed_hosts'],
                    'active_key_id' => $app['active_key_id'], 'key_version' => $key === null ? null : (int) $key['version'],
                    'created_at' => $app['created_at']];
            }, $this->apps->all());
        });
    }

    /**
     * 新 KEY 不随普通创建响应返回，管理员可另行受控查看。
     *
     * @param list<string> $hosts 接入应用允许使用的通知及回跳域名。
     * @return array{id:string} 新应用身份。
     */
    public function create(string $name, string $pid, array $hosts, ConfigurationActionContext $actor): array
    {
        $hosts = $this->hosts($hosts);
        return $this->transaction(function () use ($name, $pid, $hosts, $actor): array {
            $this->audit->authorize($actor);
            $number = $pid === '' ? (string) random_int(100000000, 999999999) : $pid;
            $this->assertPidAvailable($number);
            $id = $this->apps->create($number, $name, $hosts);
            $this->credentials->rotateLocked($id, null);
            $this->defaults->applyToApp($id);
            $this->audit->record($actor, 'application.create', 'payment_app', $id, 'created', []);
            return ['id' => $id];
        });
    }

    /** @param list<string> $hosts 整体替换当前接入目标；旧订单的冻结回调目标不受影响。 */
    public function save(string $id, int $revision, string $name, string $pid, bool $enabled, array $hosts, ConfigurationActionContext $actor): void
    {
        $hosts = $this->hosts($hosts);
        $this->transaction(function () use ($id, $revision, $name, $pid, $enabled, $hosts, $actor): void {
            $this->audit->authorize($actor);
            $app = $this->apps->lock($id);
            if ((int) $app['revision'] !== $revision) { throw new BusinessException('应用已被修改，请刷新', ApiResponseConstant::STALE_VERSION); }
            $this->assertPidAvailable($pid, $id);
            $this->apps->update($app, $pid, $name, $enabled, $hosts, $this->clock->now()->format('Y-m-d H:i:s'));
            $this->audit->record($actor, 'application.save', 'payment_app', $id, 'updated', ['enabled' => $enabled]);
        });
    }

    /** @return array{id:string,version:int,key:string} 只供 no-store 响应，不能附加到应用列表。 */
    public function reveal(string $id, string $expectedId, ConfigurationActionContext $actor, string $password): array
    {
        $this->reauthenticate($actor, $password);
        return $this->transaction(function () use ($id, $expectedId, $actor): array {
            $this->audit->authorize($actor);
            $app = $this->apps->lock($id);
            if ($app['active_key_id'] !== $expectedId) { throw new BusinessException('KEY 已改变，请刷新', ApiResponseConstant::STALE_VERSION); }
            $version = $this->credentials->active($app) ?? throw new \RuntimeException('活动 KEY 缺失');
            $this->audit->record($actor, 'application.key_reveal', 'payment_app', $id, 'viewed', ['credential_version' => (int) $version['version']]);
            return ['id' => $expectedId, 'version' => (int) $version['version'], 'key' => $this->credentials->secretForVersion($id, $expectedId)];
        });
    }

    /** @return array{id:string,version:int,key:string} 旧版本立即停止入站认证，已有订单仍引用它。 */
    public function rotate(string $id, string $expectedId, ?string $key, ConfigurationActionContext $actor, string $password): array
    {
        $this->reauthenticate($actor, $password);
        return $this->transaction(function () use ($id, $expectedId, $key, $actor): array {
            $this->audit->authorize($actor);
            $result = $this->credentials->rotateLocked($id, $expectedId, $key);
            $this->audit->record($actor, 'application.key_rotate', 'payment_app', $id, 'updated', ['credential_version' => $result['version']]);
            return $result;
        });
    }

    /** @param array<string,string|null> $parameters 仅处理管理员输入的测试内容，不发 HTTP 或创建支付。 */
    public function signatureTest(array $parameters, string $key): array
    {
        return ['sign' => $this->signer->sign($parameters, $key), 'sign_type' => 'MD5',
            'matches_supplied' => isset($parameters['sign']) ? $this->signer->verify($parameters, $key) : null];
    }

    /**
     * 可选域名限制：空列表或单独的 * 表示不限域名，其余条目精确匹配，不接受子域通配或路径。
     *
     * @param list<string> $hosts 管理员输入。
     * @return list<string> 小写、去重后的域名；存在 * 时统一保存为 ['*']。
     */
    private function hosts(array $hosts): array
    {
        if (!array_is_list($hosts) || count($hosts) > 100) { throw new BusinessException('回调域名数量无效', ApiResponseConstant::VALIDATION_FAILED); }
        $normalized = [];
        foreach ($hosts as $host) {
            if (!is_string($host) || $host === '' || trim($host) !== $host) { throw new BusinessException('回调域名格式无效', ApiResponseConstant::VALIDATION_FAILED); }
            if ($host === '*') { $normalized[] = '*'; continue; }
            $host = strtolower($host);
            try { $uri = HttpTargetPolicy::uri('https://' . $host); }
            catch (BusinessException) {
                throw new BusinessException('每行填写一个完整域名，不带协议、端口或路径；不限域名请留空或填写 *', ApiResponseConstant::VALIDATION_FAILED);
            }
            if ($uri->getHost() !== $host || $uri->getPort() !== null || $uri->getPath() !== '' || $uri->getQuery() !== '' || $uri->getFragment() !== '') {
                throw new BusinessException('只填写域名，不带协议、端口或路径', ApiResponseConstant::VALIDATION_FAILED);
            }
            $normalized[] = $host;
        }
        return in_array('*', $normalized, true) ? ['*'] : array_values(array_unique($normalized));
    }

    private function assertPidAvailable(string $pid, ?string $except = null): void
    {
        if (!preg_match('/^[1-9][0-9]{0,31}$/D', $pid)) { throw new BusinessException('PID 必须为不超过 32 位的正整数', ApiResponseConstant::VALIDATION_FAILED); }
        $existing = $this->apps->findByPid($pid);
        if ($existing !== null && $existing['id'] !== $except) { throw new BusinessException('PID 已被其他应用使用', ApiResponseConstant::CONFLICT); }
    }

    private function reauthenticate(ConfigurationActionContext $actor, string $password): void
    {
        if ($actor->origin !== 'admin' || $actor->adminId === null || $actor->sessionVersion === null) {
            throw new BusinessException('需要管理员身份', ApiResponseConstant::UNAUTHENTICATED);
        }
        $this->auth->verifyPassword($actor->adminId, $actor->sessionVersion, $actor->requestId, $password);
    }

    /** 切换为自定义保留当前绑定作为起点；恢复默认由一次事务替换全部绑定，不改变订单快照。 */
    public function routing(string $id, int $revision, bool $useDefault, ConfigurationActionContext $actor): void
    {
        $this->transaction(function () use ($id, $revision, $useDefault, $actor): void {
            $this->audit->authorize($actor);
            $app = $this->apps->lock($id);
            if ((int) $app['revision'] !== $revision) { throw new BusinessException('应用已修改，请刷新', ApiResponseConstant::STALE_VERSION); }
            if ($useDefault) { $this->defaults->applyToApp($id); }
            if ((int) $app['use_default_route'] !== (int) $useDefault) {
                $this->apps->setDefaultRoute($app, $useDefault, $this->clock->now()->format('Y-m-d H:i:s'));
            }
            $this->audit->record($actor, 'application.routing', 'payment_app', $id, 'updated', ['use_default_route' => $useDefault]);
        });
    }
}
