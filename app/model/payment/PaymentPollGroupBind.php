<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/** 应用按支付方式的组绑定；游标只在新支付尝试事务提交时推进。 */
final class PaymentPollGroupBind extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_payment_poll_group_bind',
            'schema' => [
                'id' => 'string',
                'app_id' => 'string',
                'pay_type_id' => 'string',
                'poll_group_id' => 'string',
                'status' => 'int',
                'cursor' => 'int',
                'revision' => 'int',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
