<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/** 接入应用；内部 ID 不随 PID 修改，活动 KEY 指针只影响新的认证，不改历史订单。 */
final class PaymentApp extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_payment_app',
            'schema' => [
                'id' => 'string',
                'pid' => 'string',
                'name' => 'string',
                'status' => 'int',
                'use_default_route' => 'int',
                'active_key_id' => 'string',
                'revision' => 'int',
                'allowed_hosts' => 'json',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
            'json' => ['allowed_hosts'],
        ];
    }
}
