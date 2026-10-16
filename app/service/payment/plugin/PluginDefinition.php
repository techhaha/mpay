<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use app\common\validation\PaymentTypeCode;
use app\common\validation\PluginConfigValidator;
use LogicException;

/** 已安装代码的元信息；版本用于展示和记录，不承担运行准入或多版本代码选择。 */
final readonly class PluginDefinition
{
    /** 服务运行参数不属于插件设置，防止声明相同字段后被运行值悄悄覆盖。 */
    private const RESERVED = ['channel_id', 'receipt_scope_key', 'pay_type_code', 'api_config_id'];

    /**
     * @param list<string> $payTypes
     * @param list<array<string,mixed>> $configSchema 插件声明的后台字段列表。
     */
    public function __construct(
        public string $code,
        public string $version,
        public string $name,
        public array $payTypes,
        public array $configSchema,
        public string $className = '',
        public string $description = '',
    ) {
        if (
            !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) ||
            !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}$/D', $version) ||
            $name === '' ||
            !array_is_list($payTypes) ||
            $payTypes === [] ||
            count(array_filter($payTypes, [PaymentTypeCode::class, 'valid'])) !== count($payTypes)
        ) {
            throw new LogicException('插件元信息无效');
        }
        if (!mb_check_encoding($description, 'UTF-8') || strlen($description) > 8192) {
            throw new LogicException('插件说明须为最多 8 KiB 的 UTF-8 纯文本');
        }
        self::assertSchema($configSchema);
    }

    /** @param list<array<string,mixed>> $schema 检查代码声明，不执行来自数据库或请求的动态规则。 */
    public static function assertSchema(array $schema): void
    {
        if (!array_is_list($schema) || count($schema) > 128) {
            throw new LogicException('插件配置须为字段列表');
        }
        $seen = [];
        foreach ($schema as $field) {
            if (
                !is_array($field) ||
                !is_string($field['field'] ?? null) ||
                !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field['field']) ||
                isset($seen[$field['field']]) ||
                in_array($field['field'], self::RESERVED, true) ||
                !is_string($field['title'] ?? null) ||
                trim($field['title']) === '' ||
                !in_array(
                    $field['type'] ?? null,
                    ['input', 'textarea', 'number', 'select', 'list', 'switch', 'upload'],
                    true,
                ) ||
                !is_array($field['validate'] ?? null) ||
                !is_bool($field['secret'] ?? false) ||
                (($field['secret'] ?? false) &&
                    !in_array($field['type'], ['input', 'textarea'], true))
            ) {
                throw new LogicException('插件字段声明无效');
            }
            if (
                array_diff(array_keys($field), [
                    'type',
                    'field',
                    'title',
                    'validate',
                    'value',
                    'options',
                    'secret',
                    'help',
                    'visible_when',
                    'placeholder',
                    'rows',
                ]) !== []
            ) {
                throw new LogicException('插件字段包含未支持的声明属性');
            }
            foreach (['help', 'placeholder'] as $text) {
                if (
                    isset($field[$text]) &&
                    (!is_string($field[$text]) ||
                        !mb_check_encoding($field[$text], 'UTF-8') ||
                        strlen($field[$text]) > 4096)
                ) {
                    throw new LogicException('插件字段说明必须为有界纯文本');
                }
            }
            if (
                isset($field['rows']) &&
                (!in_array($field['type'], ['textarea', 'list'], true) ||
                    !is_int($field['rows']) ||
                    $field['rows'] < 2 ||
                    $field['rows'] > 20)
            ) {
                throw new LogicException('插件文本域行数无效');
            }
            if ($field['type'] === 'select') {
                $options = $field['options'] ?? null;
                $optionValues = [];
                if (
                    !is_array($options) ||
                    !array_is_list($options) ||
                    $options === [] ||
                    count($options) > 100
                ) {
                    throw new LogicException('插件选项须为非空列表');
                }
                foreach ($options as $option) {
                    if (
                        !is_array($option) ||
                        count($option) !== 2 ||
                        !is_string($option['value'] ?? null) ||
                        !is_string($option['label'] ?? null) ||
                        in_array($option['value'], $optionValues, true)
                    ) {
                        throw new LogicException('插件选项值须为唯一字符串');
                    }
                    $optionValues[] = $option['value'];
                }
            } elseif (isset($field['options'])) {
                throw new LogicException('只有选择框允许声明选项');
            }
            $seen[$field['field']] = true;
            if (
                array_key_exists('value', $field) &&
                (!PluginConfigValidator::typedValue($field['type'], $field['value']) ||
                    ($field['type'] === 'select' &&
                        !in_array($field['value'], $optionValues, true)))
            ) {
                throw new LogicException('插件默认值类型或选项无效');
            }
        }
        $fields = array_column($schema, null, 'field');
        // 上传地址由资产服务派生；在发现时拒绝显式同名字段，顺序不能影响冲突判定。
        foreach ($fields as $name => $field) {
            if (
                $field['type'] === 'upload' &&
                (isset($fields[$name . '_url']) || in_array($name . '_url', self::RESERVED, true))
            ) {
                throw new LogicException('上传字段的派生地址与插件字段或系统运行参数冲突');
            }
            if (!isset($field['visible_when'])) {
                continue;
            }
            $condition = $field['visible_when'];
            $source =
                is_array($condition) && is_string($condition['field'] ?? null)
                    ? $fields[$condition['field']] ?? null
                    : null;
            if (
                !is_array($condition) ||
                count($condition) !== 2 ||
                $source === null ||
                $condition['field'] === $name ||
                ($source['secret'] ?? false) ||
                isset($source['visible_when']) ||
                array_key_exists('value', $condition) === array_key_exists('in', $condition)
            ) {
                throw new LogicException(
                    '插件显示条件须引用无条件、非秘密字段，并声明 value 或 in',
                );
            }
            $comparisons = array_key_exists('value', $condition)
                ? [$condition['value']]
                : $condition['in'];
            if (
                !is_array($comparisons) ||
                !array_is_list($comparisons) ||
                $comparisons === [] ||
                count($comparisons) > 100
            ) {
                throw new LogicException('插件显示条件集合无效');
            }
            foreach ($comparisons as $value) {
                if (!PluginConfigValidator::typedValue($source['type'], $value)) {
                    throw new LogicException('插件显示条件比较值类型须一致');
                }
            }
        }
    }

    /** 插件声明比较忽略对象键顺序，字段和选项列表保持顺序；不比较运行中的配置值。 */
    public static function sameSchema(array $expected, mixed $stored): bool
    {
        if (!is_array($stored)) {
            return false;
        }
        $sort = static function (array $value) use (&$sort): array {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }
            return $value;
        };
        return $sort($expected) === $sort($stored);
    }
}
