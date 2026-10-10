<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 轮询组与成员的原生场景；仅接受声明字段，数据库关联和选路政策交给服务。 */
final class PaymentPollGroupValidator extends Validate
{
    protected $rule = [
        'id' => ['string', 'recordId'], 'app_id' => ['string', 'recordId'], 'group_id' => ['string', 'recordId'],
        'revision' => ['integerJson', 'between' => [0, 4294967295]], 'name' => ['require', 'string', 'max' => 128],
        'pay_type' => ['require', 'regex' => \app\common\validation\PaymentTypeCode::PATTERN], 'route_mode' => ['require', 'integerJson', 'in' => [\app\common\constant\RouteConstant::ORDER, \app\common\constant\RouteConstant::WEIGHTED, \app\common\constant\RouteConstant::FIRST_AVAILABLE]],
        'remark' => ['string', 'max' => 500], 'enabled' => ['booleanJson'], 'members' => ['array'],
        'amount' => ['integerJson', 'between' => [1, 999999999]],
        'channel_id' => ['string', 'recordId'], 'sort_no' => ['integerJson', 'between' => [0, 1000000]],
        'weight' => ['integerJson', 'between' => [1, 1000000]], 'is_default' => ['booleanJson'],
    ];
    protected $scene = [
        'create' => ['name', 'pay_type', 'route_mode', 'remark', 'members'],
        'save' => ['id', 'revision', 'name', 'route_mode', 'enabled', 'remark', 'members'],
        'bind' => ['app_id', 'pay_type', 'group_id', 'revision', 'enabled'],
        'preview' => ['app_id', 'pay_type', 'amount'],
        'member' => ['channel_id', 'sort_no', 'weight', 'is_default', 'enabled'],
    ];

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> 成员对象转换为有固定形状的数组。
     */
    public function input(string $scene, array $input): array
    {
        $fields = $this->scene[$scene] ?? throw new \LogicException('未知轮询组场景');
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) {
            throw new BusinessException('轮询组参数缺失或包含未知字段', ApiResponseConstant::VALIDATION_FAILED);
        }
        $check = (clone $this)->scene($scene)->failException(false); $check->must = $fields;
        if (!$check->check($input)) {
            throw new BusinessException('轮询组参数类型或范围无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        if (isset($input['members'])) {
            if (!array_is_list($input['members']) || count($input['members']) > 200) {
                throw new BusinessException('每组最多 200 个成员', ApiResponseConstant::VALIDATION_FAILED);
            }
            foreach ($input['members'] as &$member) {
                if (!$member instanceof \stdClass) { throw new BusinessException('成员必须是 JSON 对象', ApiResponseConstant::VALIDATION_FAILED); }
                $member = $this->input('member', get_object_vars($member));
            }
            unset($member);
        }
        return $input;
    }

    protected function integerJson(mixed $value): bool { return is_int($value); }
    protected function booleanJson(mixed $value): bool { return is_bool($value); }
    protected function recordId(string $value): bool { return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1; }
}
