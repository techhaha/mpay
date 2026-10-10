<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 流水身份与支付方式的内部关联，也是同一收款范围的行锁目标。
 * 不保存或比较平台账号，账号含义由插件声明，服务不猜字段。
 *
 * @property string $receipt_scope_key
 * @property string $account_key 插件声明的流水身份摘要。
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
                'account_key' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }

}
