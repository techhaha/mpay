<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 通道专属插件配置；参数独立保存，历史正文由版本表保留。
 * account_key 是插件声明的流水身份，query_key/session_key 分别用于查询/登录；当前 config_version 的授权由 PaymentPluginConfScope 明确列出。
 *
 * @property string $account_key
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
                'account_key' => 'string', 'query_key' => 'string', 'session_key' => 'string', 'auth_fingerprint' => 'string',
                'config_json' => 'json',
                'config_version' => 'int',
                'enabled' => 'int',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
            'hidden' => ['config_json','auth_fingerprint'],
        ];
    }

    public function channel(): \think\model\relation\HasOne
    {
        return $this->hasOne(PaymentChannel::class, 'api_config_id', 'id');
    }

    /** 所有历史版本，不以当前配置覆盖过去的配置和插件版本。 */
    public function revisions(): \think\model\relation\HasMany
    {
        return $this->hasMany(PaymentPluginConfRevision::class, 'api_config_id', 'id');
    }
}
