<?php
declare(strict_types=1);

namespace app\common\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 将已核验的插件字段声明转换为原生动态规则；只校验配置值，不解释平台账号或交易策略。 */
final class PluginConfigValidator
{
    /**
     * 声明合法性由 PluginDefinition 在发现代码时检查；此处处理保存/读取的参数值。
     * 每次创建独立 Validate，避免常驻进程累积规则、错误或场景，不修改框架内部属性。
     *
     * @param list<array<string,mixed>> $schema 已通过插件定义检查的字段列表。
     * @param array<string,mixed> $values 配置 JSON 原生值，不隐式转换字符串、整数或布尔值。
     * @return array<string,mixed> 验证后的值；默认值仅在调用方明确要求时补齐。
     * @throws BusinessException 未声明字段、类型或插件规则不符；错误按字段返回。
     */
    public function validate(array $schema, array $values, bool $applyDefaults = false): array
    {
        $fields = array_column($schema, null, 'field');
        if (array_diff_key($values, $fields)) {
            throw new BusinessException(
                '配置包含未声明字段',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        // 所有默认值先就绪，条件判断不依赖 schema 中字段排列顺序。
        foreach ($fields as $name => $field) {
            if (
                $applyDefaults &&
                !array_key_exists($name, $values) &&
                array_key_exists('value', $field)
            ) {
                $values[$name] = $field['value'];
            }
        }
        $rules = [];
        $titles = [];
        foreach ($fields as $name => $field) {
            $titles[$name] = $field['title'];
            $declared = $field['validate'];
            $condition = $field['visible_when'] ?? null;
            if (
                $condition !== null &&
                !in_array(
                    $values[$condition['field']] ?? null,
                    array_key_exists('value', $condition)
                        ? [$condition['value']]
                        : $condition['in'],
                    true,
                )
            ) {
                // 隐藏只取消必填；保留原值与其余原生规则，不裁剪配置。
                $declared = array_filter(
                    $declared,
                    static fn($rule, $key): bool => !str_starts_with(
                        is_string($key) ? $key : (string) $rule,
                        'require',
                    ),
                    ARRAY_FILTER_USE_BOTH,
                );
            }
            // 原生闭包规则也会检查 null/空字符串；仅缺失字段交给 require* 决定是否允许。
            $rules[$name] = array_merge(
                [
                    static function (mixed $value, array $data) use ($name, $field): bool|string {
                        if (!array_key_exists($name, $data)) {
                            return true;
                        }
                        if (!self::typedValue($field['type'], $value)) {
                            return $field['title'] .
                                match ($field['type']) {
                                    'number' => '必须是整数',
                                    'switch' => '必须是布尔值',
                                    'list' => '必须是最多 100 项的文本列表，每项为 1～128 字节',
                                    default => '必须是有效的文本',
                                };
                        }
                        if (
                            $field['type'] === 'select' &&
                            !in_array($value, array_column($field['options'], 'value'), true)
                        ) {
                            return $field['title'] . '请选择声明的选项';
                        }
                        return true;
                    },
                ],
                $declared,
            );
        }
        $validator = (new Validate())
            ->useZh()
            ->rule($rules, $titles)
            ->batch()
            ->failException(false);
        if (!$validator->check($values)) {
            $errors = [];
            foreach ($validator->getError(true) as $name => $message) {
                $errors['configuration.' . $name] = [(string) $message];
            }
            throw new BusinessException(
                '插件配置校验失败',
                ApiResponseConstant::VALIDATION_FAILED,
                $errors,
            );
        }
        if (strlen(json_encode($values, JSON_THROW_ON_ERROR)) > 65536) {
            throw new BusinessException(
                '插件配置超过 64 KiB',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        return $values;
    }

    /** schema 默认值与提交值共用的 JSON 类型约定；缺失由必填规则处理，不把 null 转成空字符串。 */
    public static function typedValue(string $type, mixed $value): bool
    {
        return match ($type) {
            'number' => is_int($value),
            'switch' => is_bool($value),
            'list' => is_array($value) &&
                array_is_list($value) &&
                count($value) <= 100 &&
                count(
                    array_filter(
                        $value,
                        static fn($item): bool => !is_string($item) ||
                            $item === '' ||
                            strlen($item) > 128 ||
                            !mb_check_encoding($item, 'UTF-8'),
                    ),
                ) === 0,
            default => is_string($value) && mb_check_encoding($value, 'UTF-8'),
        };
    }
}
