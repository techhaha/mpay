<?php
declare(strict_types=1);

namespace app\model\system;

use app\common\base\BaseModel;

/**
 * 按配置键保存系统配置版本；配置正文按明确分组存入 JSON，公开输出由服务白名单控制。
 * 系统设置只保存 schema 允许的公开配置值。
 *
 * @property string $config_key
 * @property int $version
 */
final class SystemConfig extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_system_config',
            'schema' => [
                'id' => 'string',
                'config_key' => 'string',
                'value_json' => 'json',
                'version' => 'int',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
