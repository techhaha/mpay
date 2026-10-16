<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\constant\ApiResponseConstant;
use app\common\validation\PaymentTypeCode;
use app\exception\BusinessException;
use stdClass;
use think\Validate;

/**
 * 配置管理的 HTTP/CLI 共用输入边界：原生场景选择字段，保留 JSON 对象、整数和布尔的严格类型。
 * 只校验输入并转换已核验的对象；权限、版本竞争和通道启用由正式服务在事务内决定。
 */
final class PaymentConfigurationValidator extends Validate
{
    /** @var array<string,array> 类型先于格式/范围，避免数组或布尔值参与宽松比较。 */
    protected $rule = [
        'id' => ['string', 'recordId'],
        'expected_revision' => ['string', 'recordId'],
        'expected_configuration_version' => ['jsonInteger', 'between' => [1, 4294967295]],
        'enabled' => ['jsonBoolean'],
        'participates_default_route' => ['jsonBoolean'],
        'plugin_code' => ['string', 'regex' => '/^[a-z][a-z0-9_]{0,63}$/D'],
        'name' => ['string', 'nonBlank', 'max' => 128, 'plainName'],
        'pay_type' => ['string', 'regex' => PaymentTypeCode::PATTERN],
        'settings' => ['jsonObject'],
        'channel' => ['jsonObject'],
    ];
    protected $scene = [
        'channel-enabled' => [
            'id',
            'expected_revision',
            'expected_configuration_version',
            'enabled',
        ],
        'channel-delete' => ['id', 'expected_revision', 'expected_configuration_version'],
        'editor-create' => [
            'enabled',
            'participates_default_route',
            'plugin_code',
            'pay_type',
            'settings',
            'channel',
        ],
        'editor-save' => [
            'participates_default_route',
            'id',
            'expected_revision',
            'expected_configuration_version',
            'plugin_code',
            'pay_type',
            'settings',
            'channel',
        ],
        'plugin-save' => ['id', 'expected_revision', 'expected_configuration_version', 'settings'],
    ];

    /**
     * 保存插件时提交完整 settings；缺失、空值与 null 的语义由插件字段规则决定。
     * settings 不在此猜平台规则，随后仍由 PluginConfigValidator 对明确版本的 schema 核验。
     *
     * @param array<string,mixed> $input 根 JSON 对象的字段，嵌套对象必须仍为 stdClass。
     * @return array<string,mixed> 所选场景的完整参数；ID 为字符串，版本/金额/秒数为 int，开关为 bool。
     *     settings/channel 转为关联数组，其余字段不做隐式转换。
     * @throws BusinessException 422 字段错误，固定说明不包含凭证值或未知字段名称。
     */
    public function validate(string $operation, array $input): array
    {
        if (!isset($this->scene[$operation])) {
            throw new BusinessException(
                '未知配置操作，请查看命令帮助',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $fields = $this->scene[$operation];
        $optional = match ($operation) {
            'editor-create' => ['enabled', 'participates_default_route'],
            'editor-save' => ['participates_default_route'],
            default => [],
        };
        $this->assertFields($input, $fields, $optional);
        $validator = clone $this;
        $validator->append(
            array_fill_keys(
                array_diff(array_keys($input), ['enabled', 'participates_default_route']),
                'require',
            ),
        );
        foreach (['enabled', 'participates_default_route'] as $field) {
            if (array_key_exists($field, $input)) {
                $validator->rule($field, [static fn(mixed $value): bool => is_bool($value)]);
            }
        }
        $validator->message(array_fill_keys($fields, '字段类型或范围无效'));
        if (!$validator->scene($operation)->batch()->failException(false)->check($input)) {
            $this->reject($validator);
        }
        $result = $input;
        if (isset($input['channel'])) {
            $rules = ['name' => $this->rule['name']];
            $rules += [
                'minimum_amount' => ['jsonInteger', 'between' => [1, 999999999]],
                'maximum_amount' => ['jsonInteger', 'between' => [1, 999999999]],
                'daily_limit_amount' => ['jsonInteger', 'between' => [0, 999999999]],
                'daily_limit_count' => ['jsonInteger', 'between' => [0, 1000000]],
                'available_from' => [
                    fn(mixed $value): bool => $value === null ||
                        (is_string($value) &&
                            preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)),
                ],
                'available_to' => [
                    fn(mixed $value): bool => $value === null ||
                        (is_string($value) &&
                            preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)),
                ],
            ];
            $result['channel'] = $this->nested($input['channel'], $rules, 'channel');
            $channel = $result['channel'];
            if ($channel['minimum_amount'] > $channel['maximum_amount']) {
                throw new BusinessException(
                    '单笔最大金额不能小于最小金额',
                    ApiResponseConstant::VALIDATION_FAILED,
                    ['channel.maximum_amount' => ['不能小于单笔最小金额']],
                );
            }
            if (
                ($channel['available_from'] === null) !== ($channel['available_to'] === null) ||
                ($channel['available_from'] !== null &&
                    $channel['available_from'] === $channel['available_to'])
            ) {
                throw new BusinessException(
                    '可用时段须同时填写不同的开始和结束时间，或全部留空',
                    ApiResponseConstant::VALIDATION_FAILED,
                    ['channel.available_to' => ['请填写完整且起止不同的可用时段']],
                );
            }
        }
        if (isset($input['settings'])) {
            // 只转换根对象；字段类型交给插件 schema，不通过 JSON 往返把对象误转换为列表。
            $result['settings'] = get_object_vars($input['settings']);
        }
        return $result;
    }

    /**
     * @param array<string,array> $rules 代码中固定的内层规则，客户端不能定义校验器。
     * @return array<string,mixed> 仅将当前已核验对象转换为数组，不丢失更内层的对象/列表区别。
     */
    private function nested(stdClass $object, array $rules, string $prefix): array
    {
        $values = get_object_vars($object);
        $this->assertFields($values, array_keys($rules), [], $prefix);
        $validator = clone $this;
        // 时段允许 null，已有闭包会始终执行；名称与金额必须提供有效非空值。
        $validator->append(
            array_fill_keys(
                array_diff(array_keys($rules), ['available_from', 'available_to']),
                'require',
            ),
        );
        $validator->message(array_fill_keys(array_keys($rules), '字段类型或范围无效'));
        if (!$validator->batch()->failException(false)->check($values, $rules)) {
            $this->reject($validator, $prefix);
        }
        return $values;
    }

    /**
     * 封闭管理对象要求字段存在，null 是否允许再由规则判断；不回显未知字段，避免把秘密写进错误。
     *
     * @param array<string,mixed> $values
     * @param list<string> $fields
     * @param list<string> $optional
     */
    private function assertFields(
        array $values,
        array $fields,
        array $optional,
        string $prefix = 'configuration',
    ): void {
        if (
            array_diff(array_keys($values), $fields) !== [] ||
            array_diff($fields, $optional, array_keys($values)) !== []
        ) {
            throw new BusinessException(
                '管理输入存在未知字段或缺少完整参数',
                ApiResponseConstant::VALIDATION_FAILED,
                [$prefix => ['请提供该操作要求的完整字段']],
            );
        }
    }

    private function reject(Validate $validator, string $prefix = ''): never
    {
        $errors = [];
        foreach ($validator->getError(true) as $field => $message) {
            $errors[$prefix === '' ? $field : $prefix . '.' . $field] = [$message];
        }
        throw new BusinessException(
            '配置管理参数校验失败',
            ApiResponseConstant::VALIDATION_FAILED,
            $errors,
        );
    }

    /** JSON 的数字和开关不能用原生 integer/boolean 的宽松转换替代。 */
    protected function jsonInteger(mixed $value): bool
    {
        return is_int($value);
    }
    protected function jsonBoolean(mixed $value): bool
    {
        return is_bool($value);
    }
    protected function jsonObject(mixed $value): bool
    {
        return $value instanceof stdClass;
    }

    /** 大整数 ID 保持十进制字符串，比较上限不经过浮点或有符号 int。 */
    protected function recordId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1 &&
            (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }

    protected function nonBlank(string $value): bool
    {
        return trim($value) !== '' &&
            mb_check_encoding($value, 'UTF-8') &&
            !str_contains($value, "\0");
    }

    /** 名称是单行公开标签，在进入业务服务前拒绝控制字符。 */
    protected function plainName(string $value): bool
    {
        return !preg_match('/[\x00-\x1f\x7f]/', $value);
    }
}
