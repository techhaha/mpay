<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付执行回写及回调的验证、定位和处理摘要；不替代 ReceiptFlow 中的到账事实。
 * 执行认领没有平台事件时 event_key/payload_digest 保持 null，不制造虚构渠道身份。
 * 未能定位支付单时允许 pay_no 为 null，使用 request_id 和摘要关联诊断。
 *
 * @property string|null $pay_no
 * @property string $request_id
 * @property string $result_code
 * @property string|null $payload_digest
 */
final class PayCallbackLog extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_pay_callback_log',
            'schema' => [
                'id' => 'string',
                'pay_no' => 'string',
                'request_id' => 'string',
                'event_key' => 'string',
                'payload_digest' => 'string',
                'result_code' => 'string',
                'summary_json' => 'json',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
