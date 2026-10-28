<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/** 统一收款回调记录；各次投递可重复，最终成功证据及消费唯一约束保留在 ReceiptFlow。 */
final class PayCallbackLog extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_pay_callback_log',
            'schema' => [
                'id' => 'string',
                'pay_no' => 'string',
                'channel_id' => 'string',
                'plugin_code' => 'string',
                'received_at' => 'string',
                'amount' => 'int',
                'paid_at' => 'string',
                'result_status' => 'int',
                'message' => 'string',
                'payload_text' => 'string',
                'payload_truncated' => 'int',
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
