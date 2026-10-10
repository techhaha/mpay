<?php
declare(strict_types=1);

namespace app\common\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** V2 字段列表到 ThinkPHP Validate 的轻量适配；规则来自插件代码，HTTP 只能提交字段值。 */
final class PluginConfigValidator extends Validate
{
    /** 服务运行参数不属于插件设置，防止声明相同字段后被运行值悄悄覆盖。 */
    private const RESERVED = ['channel_id', 'receipt_scope_key', 'receipt_qrcode_image_url', 'pay_type_code', 'configuration_id', 'api_config_id', 'collection_configuration_ids'];

    /** @param list<array<string,mixed>> $schema 检查代码声明，不执行来自数据库或请求的动态规则。 */
    public static function assertSchema(array $schema): void
    {
        if (!array_is_list($schema) || count($schema) > 128) { throw new \LogicException('插件配置须为字段列表'); }
        $seen = [];
        foreach ($schema as $field) {
            if (!is_array($field) || !is_string($field['field'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field['field'])
                || isset($seen[$field['field']]) || in_array($field['field'], self::RESERVED, true)
                || !is_string($field['title'] ?? null) || trim($field['title']) === ''
                || !in_array($field['type'] ?? null, ['input', 'textarea', 'number', 'select', 'list', 'switch', 'upload'], true)
                || !is_array($field['validate'] ?? null) || !is_bool($field['secret'] ?? false)
                || (($field['secret'] ?? false) && array_key_exists('value', $field))) {
                throw new \LogicException('插件字段声明无效');
            }
            if (array_diff(array_keys($field), ['type','field','title','validate','value','options','secret','help','visible_when','placeholder','rows']) !== []) {
                throw new \LogicException('插件字段包含未支持的声明属性');
            }
            foreach (['help', 'placeholder'] as $text) {
                if (isset($field[$text]) && (!is_string($field[$text]) || !mb_check_encoding($field[$text], 'UTF-8') || strlen($field[$text]) > 4096)) {
                    throw new \LogicException('插件字段说明必须为有界纯文本');
                }
            }
            if (isset($field['rows']) && (!in_array($field['type'], ['textarea','list'], true) || !is_int($field['rows']) || $field['rows'] < 2 || $field['rows'] > 20)) {
                throw new \LogicException('插件文本域行数无效');
            }
            if ($field['type'] === 'select') {
                $options = $field['options'] ?? null; $optionValues = [];
                if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > 100) { throw new \LogicException('插件选项须为非空列表'); }
                foreach ($options as $option) {
                    if (!is_array($option) || count($option) !== 2 || !is_string($option['value'] ?? null) || !is_string($option['label'] ?? null)
                        || in_array($option['value'], $optionValues, true)) { throw new \LogicException('插件选项值须为唯一字符串'); }
                    $optionValues[] = $option['value'];
                }
            } elseif (isset($field['options'])) { throw new \LogicException('只有选择框允许声明选项'); }
            $seen[$field['field']] = true;
            if (array_key_exists('value', $field) && (!self::typedValue($field['type'], $field['value'])
                || ($field['type'] === 'select' && !in_array($field['value'], $optionValues, true)))) {
                throw new \LogicException('插件默认值类型或选项无效');
            }
        }
        $fields = array_column($schema, null, 'field');
        foreach ($fields as $name => $field) {
            if (!isset($field['visible_when'])) { continue; }
            $condition = $field['visible_when']; $source = is_array($condition) ? ($fields[$condition['field'] ?? ''] ?? null) : null;
            if (!is_array($condition) || count($condition) !== 2 || !array_key_exists('value', $condition) || $source === null
                || $condition['field'] === $name || ($source['secret'] ?? false) || isset($source['visible_when'])
                || !self::typedValue($source['type'], $condition['value'])) { throw new \LogicException('插件显示条件必须引用无条件、非秘密字段，比较值类型须一致'); }
        }
    }

    /**
     * @param list<array<string,mixed>> $schema
     * @param array<string,mixed> $values
     * @return array<string,mixed> 严格 JSON 类型，默认值仅在新建/保存时应用；历史快照不补新默认值。
     */
    public function validate(array $schema, #[\SensitiveParameter] array $values, bool $applyDefaults = false): array
    {
        self::assertSchema($schema);
        $fields = array_column($schema, null, 'field');
        if (array_diff_key($values, $fields)) { throw new BusinessException('配置包含未声明字段', ApiResponseConstant::VALIDATION_FAILED); }
        $rules = []; $errors = [];
        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $values) && $applyDefaults && array_key_exists('value', $field)) { $values[$name] = $field['value']; }
            $rules[$name] = $field['validate'];
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $value = $values[$name];
            $typed = self::typedValue($field['type'], $value);
            if (!$typed) { $errors['configuration.' . $name] = ['配置类型不符合声明']; }
            $rules[$name] = $field['validate'];
            if ($field['type'] === 'select' && !in_array($value, array_column($field['options'] ?? [], 'value'), true)) {
                $errors['configuration.' . $name] = ['请选择声明的选项'];
            }
        }
        // 已提供的 0/false/空字符串也执行规则，避免框架可选字段跳过范围检查。
        if ($errors === []) {
            $check = clone $this; $check->must = array_keys($values);
            if (!$check->batch()->failException(false)->check($values, $rules)) {
                foreach (array_keys($check->getError(true)) as $name) { $errors['configuration.' . $name] = ['配置范围不符合声明']; }
            }
        }
        if ($errors !== []) { throw new BusinessException('插件配置校验失败', ApiResponseConstant::VALIDATION_FAILED, $errors); }
        if (strlen(json_encode($values, JSON_THROW_ON_ERROR)) > 65536) { throw new BusinessException('插件配置超过 64 KiB', ApiResponseConstant::VALIDATION_FAILED); }
        return $values;
    }

    /** JSON null 不隐式转为空值；数字和开关保留原生类型，0/false 是有效的已提供值。 */
    private static function typedValue(string $type, mixed $value): bool
    {
        return match ($type) {
            'number' => is_int($value), 'switch' => is_bool($value),
            'list' => is_array($value) && array_is_list($value) && count($value) <= 100
                && count(array_filter($value, static fn ($item): bool => !is_string($item) || $item === '' || strlen($item) > 128 || !mb_check_encoding($item, 'UTF-8'))) === 0,
            default => is_string($value) && mb_check_encoding($value, 'UTF-8'),
        };
    }

    /**
     * @param list<array<string,mixed>> $schema
     * @return array{values:array,secret_present:array<string,bool>} 秘密只返回存在标记。
     */
    public function publicValues(array $schema, #[\SensitiveParameter] array $values): array
    {
        $values = $this->validate($schema, $values); $present = [];
        foreach ($schema as $field) {
            if ($field['secret'] ?? false) { $present[$field['field']] = isset($values[$field['field']]) && $values[$field['field']] !== ''; unset($values[$field['field']]); }
        }
        return ['values' => $values, 'secret_present' => $present];
    }

    /** 配置对象比较忽略键顺序，列表保持顺序；用于识别无变化保存，不决定插件启用。 */
    public static function sameSchema(array $expected, mixed $stored): bool
    {
        if (!is_array($stored)) { return false; }
        $sort = static function (array $value) use (&$sort): array {
            if (!array_is_list($value)) { ksort($value); }
            foreach ($value as &$item) { if (is_array($item)) { $item = $sort($item); } }
            return $value;
        };
        return $sort($expected) === $sort($stored);
    }
}
