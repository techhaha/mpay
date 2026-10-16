<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 业务单的一次具体支付执行；pay_amount 在等待收款时可为识别金额，确认时恢复原金额。
 * personal_receipt 保存 original_amount、receipt_amount 和 notified_amount，不再维护独立识别/实收列。
 * 数据库固定 attempt_no=1 并约束 biz_no/attempt_no 唯一；该模型不自行确认支付成功。
 *
 * @property string $pay_no 易支付 trade_no 的内部来源。
 * @property string $biz_no
 * @property-read int $attempt_no 数据库生成的固定值 1。
 * @property int $pay_amount 当前支付金额，分；收款准备后含识别偏移，成功后为原金额。
 * @property string $channel_revision_id
 * @property string|null $receipt_flow_id
 */
final class PayOrder extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_pay_order',
            'schema' => [
                'id' => 'string',
                'pay_no' => 'string',
                'biz_no' => 'string',
                'trace_no' => 'string',
                'attempt_no' => 'int',
                'poll_group_id' => 'string',
                'route_mode' => 'int',
                'channel_id' => 'string',
                'channel_revision_id' => 'string',
                'pay_type_id' => 'string',
                'plugin_code' => 'string',
                'pay_amount' => 'int',
                'receipt_scope_key' => 'string',
                'receipt_flow_id' => 'string',
                'confirmation_source' => 'int',
                'status' => 'int',
                'channel_order_no' => 'string',
                'channel_trade_no' => 'string',
                'request_at' => 'string',
                'expire_at' => 'string',
                'paid_at' => 'string',
                'closed_at' => 'string',
                'failed_at' => 'string',
                'timeout_at' => 'string',
                'ext_json' => 'json',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }

    public function bizOrder(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(BizOrder::class, 'biz_no', 'biz_no');
    }

    /** 本支付单创建时固定的历史版本，不跟随通道的 current_revision_id 变化。 */
    public function channelRevision(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(PaymentChannelRevision::class, 'channel_revision_id', 'id');
    }

    /** 原金额用于业务核对；尚未准备识别值时，pay_amount 本身就是原金额。 */
    public function originalAmount(): int
    {
        return (int) ($this['ext_json']['personal_receipt']['original_amount'] ??
            $this['pay_amount']);
    }

    /** 收银台和回调使用插件冻结的识别值；成功恢复 pay_amount 后仍可查看原提示金额。 */
    public function receiptAmount(): int
    {
        return (int) ($this['ext_json']['personal_receipt']['receipt_amount'] ??
            $this['pay_amount']);
    }

    /** 实际到账证据来自通知或人工确认，不以二维码或识别值补造。 */
    public function receivedAmount(): ?int
    {
        $amount = $this['ext_json']['personal_receipt']['notified_amount'] ?? null;
        return $amount === null ? null : (int) $amount;
    }
}
