<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/** 一次退款请求及其持久结果；金额单位为分，不在模型中调用平台或变更原订单。 */
final class RefundOrder extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_refund_order',
            'schema' => [
                'id' => 'string',
                'refund_no' => 'string',
                'pay_no' => 'string',
                'request_key' => 'string',
                'refund_amount' => 'int',
                'status' => 'int',
                'channel_refund_no' => 'string',
                'result_code' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
