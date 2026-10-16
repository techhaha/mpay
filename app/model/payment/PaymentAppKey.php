<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/** 应用 KEY 的不可变凭证版本；由订单按版本引用，重置仅追加记录，不删除旧单签名材料。 */
final class PaymentAppKey extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_payment_app_key',
            'schema' => [
                'id' => 'string',
                'app_id' => 'string',
                'version' => 'int',
                'secret' => 'string',
                'key_fingerprint' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
            'hidden' => ['secret', 'key_fingerprint'],
        ];
    }
}
