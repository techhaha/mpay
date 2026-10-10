<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\constant\ApiResponseConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\model\payment\PaymentApp;
use app\model\payment\PaymentAppKey;
use app\repository\payment\config\PaymentAppKeyRepository;
use app\repository\payment\config\PaymentAppRepository;

/** 应用凭证合同：新接入只接受当前版本；冻结旧单可以持续使用自己的历史密钥。 */
final class PaymentAppCredentialService
{
    public function __construct(private readonly PaymentAppRepository $apps, private readonly PaymentAppKeyRepository $keys,
        private readonly Clock $clock) {}

    /** 调用方已读取/锁定应用；不单独查询活动指针，避免拼接两次读取的不同版本。 */
    public function active(PaymentApp $app): ?PaymentAppKey
    {
        if ($app['active_key_id'] === null) { return null; }
        return $this->keys->version($app['id'], $app['active_key_id']) ?? throw new \RuntimeException('应用活动凭证缺失');
    }

    /**
     * 调用方拥有事务并完成管理授权；本方法锁应用，追加凭证版本并切换指针，不嵌套事务。
     * @return array{id:string,version:int,key:string} key 只供专用 no-store 响应，不进入日志。
     */
    public function rotateLocked(string $appId, ?string $expectedId, #[\SensitiveParameter] ?string $customKey = null): array
    {
        $app = $this->apps->lock($appId);
        if ($expectedId !== null && $app['active_key_id'] !== $expectedId) {
            throw new BusinessException('活动 KEY 已改变，请刷新后操作', ApiResponseConstant::CONFLICT);
        }
        $secret = $customKey ?? bin2hex(random_bytes(32));
        if (strlen($secret) < 16 || strlen($secret) > 128 || !preg_match('/^[\x21-\x7e]+$/D', $secret)) {
            throw new BusinessException('KEY 须为 16～128 位无空格可打印字符', ApiResponseConstant::VALIDATION_FAILED);
        }
        $active = $this->active($app);
        if ($active !== null && hash_equals($active['key_fingerprint'], hash('sha256', $secret))) {
            throw new BusinessException('新 KEY 不能与当前 KEY 相同', ApiResponseConstant::CONFLICT);
        }
        $id = $this->keys->append($appId, $secret, hash('sha256', $secret));
        $this->apps->activateKey($app, $id, $this->clock->now()->format('Y-m-d H:i:s'));
        $key = $this->keys->version($appId, $id) ?? throw new \RuntimeException('新增凭证缺失');
        return ['id' => $id, 'version' => (int) $key['version'], 'key' => $secret];
    }

    /** 按应用及版本共同限定；历史版本缺失必须报错，禁止改用当前 KEY 重签原单。 */
    public function secretForVersion(string $appId, string $keyId): string
    {
        $key = $this->keys->version($appId, $keyId) ?? throw new \RuntimeException('应用凭证版本不存在');
        return $key['secret'];
    }
}
