<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/** 轮询组成员；同一通道在不同组可拥有不同排序、权重及默认标记。 */
final class PaymentPollGroupChannel extends BaseModel
{
    protected function getOptions(): array
    {
        return ['table' => 'ma_payment_poll_group_channel', 'schema' => [
            'id' => 'string',
            'poll_group_id' => 'string',
            'channel_id' => 'string',
            'sort_no' => 'int',
            'weight' => 'int',
            'is_default' => 'int',
            'status' => 'int',
            'created_at' => 'string',
            'updated_at' => 'string',
        ]];
    }
}
