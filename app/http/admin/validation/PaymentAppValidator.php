<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 应用后台的 JSON 场景和字段白名单；密码与 KEY 保持原字节，不在错误中回显。 */
final class PaymentAppValidator extends Validate
{
    protected $rule = [
        'id' => ['string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'expected_id' => ['string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'revision' => ['integerJson', 'between' => [1, 4294967295]],
        'name' => ['require', 'string', 'max' => 128], 'pid' => ['string', 'max' => 32],
        'enabled' => ['booleanJson'], 'use_default_route' => ['booleanJson'], 'allowed_hosts' => ['array'],
        'reason' => ['require', 'string', 'max' => 500], 'password' => ['require', 'string', 'max' => 72],
        'key' => ['string', 'max' => 128], 'parameters' => ['objectJson'],
    ];
    protected $scene = [
        'routing' => ['id', 'revision', 'use_default_route'],
        'create' => ['name', 'pid', 'allowed_hosts'],
        'save' => ['id', 'revision', 'name', 'pid', 'enabled', 'allowed_hosts'],
        'reveal' => ['id', 'expected_id', 'password'],
        'rotate' => ['id', 'expected_id', 'password', 'reason', 'key'],
        'signature' => ['parameters', 'key'],
    ];

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> 每次克隆场景，避免常驻容器中的规则累积。
     */
    public function input(string $scene, #[\SensitiveParameter] array $input): array
    {
        $fields = $this->scene[$scene] ?? throw new \LogicException('未知应用管理场景');
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) {
            throw new BusinessException('应用参数缺失或包含未知字段', ApiResponseConstant::VALIDATION_FAILED);
        }
        $validator = (clone $this)->scene($scene)->failException(false);
        $validator->must = $fields;
        if (!$validator->check($input) || (isset($input['reason']) && trim($input['reason']) === '')) {
            throw new BusinessException('应用参数类型或范围无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        if ($scene === 'signature') {
            $input['parameters'] = get_object_vars($input['parameters']);
            if (count($input['parameters']) > 128 || strlen(json_encode($input['parameters'], JSON_THROW_ON_ERROR)) > 32768) {
                throw new BusinessException('签名参数过多', ApiResponseConstant::VALIDATION_FAILED);
            }
            foreach ($input['parameters'] as $name => $value) {
                if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', (string) $name) || ($value !== null && !is_string($value))) {
                    throw new BusinessException('签名参数名称或类型无效', ApiResponseConstant::VALIDATION_FAILED);
                }
            }
        }
        return $input;
    }

    protected function integerJson(mixed $value): bool { return is_int($value); }
    protected function booleanJson(mixed $value): bool { return is_bool($value); }
    protected function objectJson(mixed $value): bool { return $value instanceof \stdClass; }
}
