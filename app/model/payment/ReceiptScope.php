<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 通道配置与支付方式的内部关联及交易确认锁；识别值由插件按通道锁分配。
 * 不保存或比较平台账号；实际账号合并和平台登录由插件自行处理。
 *
 * @property string $receipt_scope_key
 * @property string $api_config_id 通道专属配置 ID，不代表平台账号。
 */
final class ReceiptScope extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_receipt_scope',
            'schema' => [
                'id' => 'string',
                'receipt_scope_key' => 'string',
                'pay_type_id' => 'string',
                'api_config_id' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
