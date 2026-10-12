<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;
use think\model\relation\HasMany;
use think\model\relation\HasOne;

/**
 * 通道专属插件配置；参数独立保存，历史正文由版本表保留。
 *
 * @property array<string,mixed> $config_json 插件配置原值，公开序列化时隐藏。
 * @property int $config_version 活动配置版本序号。
 */
final class PaymentPluginConf extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_payment_plugin_conf',
            'schema' => [
                'id' => 'string',
                'plugin_code' => 'string',
                'name' => 'string',
                'config_json' => 'json',
                'config_version' => 'int',
                'enabled' => 'int',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
            'hidden' => ['config_json'],
        ];
    }

    public function channel(): HasOne
    {
        return $this->hasOne(PaymentChannel::class, 'api_config_id', 'id');
    }

    /** 所有历史版本，不以当前配置覆盖过去的配置和插件版本。 */
    public function revisions(): HasMany
    {
        return $this->hasMany(PaymentPluginConfRevision::class, 'api_config_id', 'id');
    }
}
