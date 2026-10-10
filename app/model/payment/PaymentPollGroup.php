<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/** 支付方式的通道轮询组；策略与成员配置由服务校验，模型不执行选路。 */
final class PaymentPollGroup extends BaseModel
{
    protected function getOptions(): array
    {
        return ['table' => 'ma_payment_poll_group', 'schema' => [
            'id' => 'string',
            'name' => 'string',
            'pay_type_id' => 'string',
            'route_mode' => 'int',
            'status' => 'int',
            'revision' => 'int',
            'remark' => 'string',
            'created_at' => 'string',
            'updated_at' => 'string',
        ]];
    }
}
