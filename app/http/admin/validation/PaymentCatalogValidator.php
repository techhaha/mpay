<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 目录维护的原生验证器；严格布尔/整数和字段白名单防止表单隐式扩大写入范围。 */
final class PaymentCatalogValidator extends Validate
{
    protected $rule = [
        'id' => ['nullableId'], 'revision' => ['jsonInteger', 'between' => [0,4294967295]],
        'code' => ['string', 'regex' => '/^[a-z][a-z0-9_]{0,31}$/D'],
        'name' => ['string','length' => [1,64]], 'enabled' => ['jsonBoolean'],
        'sort_order' => ['jsonInteger','between' => [0,65535]],
        'icon_asset_id' => ['nullableId'], 'logo_asset_id' => ['nullableId'],
        
    ];
    protected $scene = [
        'save' => ['id','revision','code','name','enabled','sort_order','icon_asset_id','logo_asset_id'],
        'delete' => ['id','revision'], 'enabled' => ['code','revision','enabled'], 'refresh' => [],
    ];

    public function validate(string $scene, array $input): array
    {
        $fields = $this->scene[$scene] ?? throw new \LogicException('未知目录操作');
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) {
            throw new BusinessException('目录操作字段不完整', ApiResponseConstant::VALIDATION_FAILED);
        }
        $validator = clone $this;
        if (!$validator->scene($scene)->failException(false)->check($input)
            || (isset($input['name']) && (trim($input['name']) === '' || preg_match('/[\x00-\x1f\x7f<>]/u', $input['name'])))
            || ($scene === 'delete' && $input['id'] === null)) {
            throw new BusinessException('请检查目录名称、编码和字段格式', ApiResponseConstant::VALIDATION_FAILED);
        }
        if (isset($input['name'])) { $input['name'] = trim($input['name']); }
        return $input;
    }

    protected function jsonInteger(mixed $value): bool { return is_int($value); }
    protected function jsonBoolean(mixed $value): bool { return is_bool($value); }
    protected function nullableId(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/^[1-9][0-9]{0,19}$/D', $value)
            && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0));
    }
}
