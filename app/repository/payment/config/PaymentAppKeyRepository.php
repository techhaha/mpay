<?php
declare(strict_types=1);

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentAppKey;

/**
 * 只追加 KEY 版本；活动指针由 PaymentApp 保存。
 *
 * @extends BaseRepository<PaymentAppKey>
 */
final class PaymentAppKeyRepository extends BaseRepository
{
    public function __construct(PaymentAppKey $model)
    {
        parent::__construct($model);
    }

    public function version(string $appId, string $id): ?PaymentAppKey
    {
        return $this->model->where('app_id', $appId)->where('id', $id)->find();
    }

    /** 调用方持应用行锁；版本当前读防止等待锁后沿用旧快照，不自行开启事务。 */
    public function append(string $appId, string $value, string $fingerprint): string
    {
        $this->requireTransaction();
        $number =
            (int) ($this->model
                ->where('app_id', $appId)
                ->order('version', 'desc')
                ->lock(true)
                ->value('version') ?? 0) + 1;
        if ($number > 4294967295) {
            throw new \RuntimeException('凭证版本序号已用尽');
        }
        return $this->insert(
            [
                'app_id' => $appId,
                'version' => $number,
                'secret' => $value,
                'key_fingerprint' => $fingerprint,
            ],
            ['app_id', 'version', 'secret', 'key_fingerprint'],
        );
    }
}
