<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 收款配置的不可变版本正文，与版本范围绑定共同保留授权历史。
 * config_version 是 api_config_id 下的递增号；id 仅为数据库记录标识，不替代事件的逻辑版本引用。
 *
 * @property string $api_config_id
 * @property int $config_version
 * @property string $plugin_version
 * @property array<string,mixed> $config_json 配置原值，禁止模型公开序列化。
 */
final class PaymentPluginConfRevision extends BaseModel
{
    protected function getOptions(): array
    {
        return ['table' => 'ma_payment_plugin_conf_revision', 'schema' => [
            'id' => 'string', 'api_config_id' => 'string', 'config_version' => 'int',
            'plugin_version' => 'string', 'config_json' => 'json',
            'created_at' => 'string',
        ], 'hidden' => ['config_json'], 'updateTime' => false];
    }
}
