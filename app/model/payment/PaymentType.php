<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 可管理的支付方式字典；图标引用登记图片，未设置时由展示服务选择内置品牌素材。
 * enabled 只表示方式开关，不证明存在可用通道或具备收款能力。
 *
 * @property string $code 易支付方式编码。
 * @property int $enabled
 */
final class PaymentType extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_payment_type',
            'schema' => [
                'id' => 'string',
                'code' => 'string',
                'name' => 'string',
                'enabled' => 'int',
                'sort_order' => 'int',
                'revision' => 'int',
                'default_poll_group_id' => 'string',
                'icon_asset_id' => 'string',
                'logo_asset_id' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }

}
