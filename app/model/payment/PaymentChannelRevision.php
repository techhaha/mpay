<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 通道和完整插件配置的历史配置快照，防止活动配置修改改变旧支付单语义。
 * channel_id/revision 唯一；config_version 描述冻结时的账号配置序号，不是快照主键。
 *
 * @property string $channel_id
 * @property int $revision 通道内递增版本序号。
 * @property int $config_version
 * @property array<string,mixed> $snapshot_json 配置快照，禁止模型公开序列化。
 * @property string $snapshot_digest
 */
final class PaymentChannelRevision extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_payment_channel_revision',
            'schema' => [
                'id' => 'string',
                'channel_id' => 'string',
                'revision' => 'int',
                'api_config_id' => 'string',
                'config_version' => 'int',
                'snapshot_json' => 'json',
                'snapshot_digest' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
            'hidden' => ['snapshot_json'],
        ];
    }
}
