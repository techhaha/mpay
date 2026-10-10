<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use app\common\validation\PluginConfigValidator;

/** 已安装代码的元信息；版本用于展示和记录，不承担运行准入或多版本代码选择。 */
final readonly class PluginDefinition
{
    /**
     * @param list<string> $payTypes
     * @param list<array<string,mixed>> $configSchema 插件声明的后台字段列表。
     */
    public function __construct(public string $code, public string $version, public string $name,
        public array $payTypes, public array $configSchema, public string $className = '', public string $description = '')
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}$/D', $version)
            || $name === '' || !array_is_list($payTypes) || $payTypes === [] || count(array_filter($payTypes, [\app\common\validation\PaymentTypeCode::class, 'valid'])) !== count($payTypes)) {
            throw new \LogicException('插件元信息无效');
        }
        if (!mb_check_encoding($description, 'UTF-8') || strlen($description) > 8192) { throw new \LogicException('插件说明须为最多 8 KiB 的 UTF-8 纯文本'); }
        PluginConfigValidator::assertSchema($configSchema);

    }
}
