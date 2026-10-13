<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\common\constant\RouteConstant;
use app\common\validation\PaymentTypeCode;
use app\exception\BusinessException;
use LogicException;
use stdClass;
use think\Validate;

/** 轮询组与成员的原生场景；仅接受声明字段，数据库关联和选路政策交给服务。 */
final class PaymentPollGroupValidator extends Validate
{
    protected $rule = [
        'id' => ['string', 'recordId'], 'app_id' => ['string', 'recordId'], 'group_id' => ['string', 'recordId'],
        'revision' => ['integerJson', 'between' => [0, 4294967295]], 'name' => ['require', 'string', 'max' => 128],
        'pay_type' => ['require', 'regex' => PaymentTypeCode::PATTERN], 'route_mode' => ['require', 'integerJson', 'in' => [RouteConstant::ORDER, RouteConstant::WEIGHTED, RouteConstant::FIRST_AVAILABLE]],
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
        $fields = $this->scene[$scene] ?? throw new LogicException('未知轮询组场景');
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) {
            throw new BusinessException('轮询组参数缺失或包含未知字段', ApiResponseConstant::VALIDATION_FAILED);
        }
        $check = (clone $this)->scene($scene)->failException(false);
        // 可空文本、空成员列表和 false 显式校验类型，其余标识/数值不能为空。
        $check->append([
            'id' => 'require', 'app_id' => 'require', 'group_id' => 'require',
            'revision' => 'require', 'channel_id' => 'require', 'amount' => 'require',
            'sort_no' => 'require', 'weight' => 'require',
        ]);
        $check->rule('remark', [static fn (mixed $value): bool => is_string($value), ...$this->rule['remark']]);
        $check->rule('members', [static fn (mixed $value): bool => is_array($value)]);
        foreach (['enabled', 'is_default'] as $field) {
            $check->rule($field, [static fn (mixed $value): bool => is_bool($value)]);
        }
        if (!$check->check($input)) {
            throw new BusinessException('轮询组参数类型或范围无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        if (isset($input['members'])) {
            if (!array_is_list($input['members']) || count($input['members']) > 200) {
                throw new BusinessException('每组最多 200 个成员', ApiResponseConstant::VALIDATION_FAILED);
            }
            foreach ($input['members'] as &$member) {
                if (!$member instanceof stdClass) { throw new BusinessException('成员必须是 JSON 对象', ApiResponseConstant::VALIDATION_FAILED); }
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
