<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 配置某个不可变版本允许访问的真实范围；不复制支付方式或把配置 ID 当匹配范围。
 * 新配置版本追加新的集合，历史绑定不删除；是否参与新单及本次采集由服务按当前版本核验。
 *
 * @property string $api_config_id
 * @property int $config_version
 * @property string $receipt_scope_key
 */
final class PaymentPluginConfScope extends BaseModel
{
    protected function getOptions(): array
    {
        return ['table' => 'ma_payment_plugin_conf_scope', 'schema' => [
            'id' => 'string', 'api_config_id' => 'string', 'config_version' => 'int',
            'receipt_scope_key' => 'string', 'created_at' => 'string',
        ], 'updateTime' => false];
    }

    public function scope(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(ReceiptScope::class, 'receipt_scope_key', 'receipt_scope_key');
    }
}
