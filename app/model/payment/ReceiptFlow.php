<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 与成功订单一对一绑定的真实到账流水；未匹配、重复或被拒绝的输入不另存流水。
 * 事件键与内容摘要用于重报核对；金额为整数分，paid_at 为来源时间，received_at 为接收时间。
 * @property string $pay_no 最终绑定支付号，不可改绑。
 * @property int $amount 实际收款金额，分。
 */
final class ReceiptFlow extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_receipt_flow',
            'schema' => [
                'id' => 'string',
                'receipt_scope_key' => 'string',
                'plugin_code' => 'string',
                'event_key' => 'string',
                'payload_digest' => 'string',
                'platform_trade_no' => 'string',
                'amount' => 'int',
                'remark' => 'string',
                'paid_at' => 'string',
                'received_at' => 'string',
                'reason_code' => 'string',
                'pay_no' => 'string',
                'evidence_json' => 'json',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
