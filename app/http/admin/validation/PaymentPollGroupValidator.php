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
        'id' => ['require', 'string', 'recordId'],
        'app_id' => ['require', 'string', 'recordId'],
        'group_id' => ['require', 'string', 'recordId'],
        'revision' => ['require', 'integerJson', 'between' => [0, 4294967295]],
        'name' => ['require', 'string', 'max' => 128],
        'pay_type' => ['require', 'string', 'regex' => PaymentTypeCode::PATTERN],
        'route_mode' => [
            'require',
            'integerJson',
            'in' => [RouteConstant::ORDER, RouteConstant::WEIGHTED, RouteConstant::FIRST_AVAILABLE],
        ],
        'remark' => ['requireText', 'max' => 500],
        'enabled' => ['requireBoolean'],
        'members' => ['requireArray'],
        'amount' => ['require', 'integerJson', 'between' => [1, 999999999]],
        'channel_id' => ['require', 'string', 'recordId'],
        'sort_no' => ['require', 'integerJson', 'between' => [0, 1000000]],
        'weight' => ['require', 'integerJson', 'between' => [1, 1000000]],
        'is_default' => ['requireBoolean'],
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
            throw new BusinessException(
                '轮询组参数缺失或包含未知字段',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $check = (clone $this)->scene($scene)->failException(false);
        if (!$check->check($input)) {
            throw new BusinessException(
                '轮询组参数类型或范围无效',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        if (isset($input['members'])) {
            if (!array_is_list($input['members']) || count($input['members']) > 200) {
                throw new BusinessException(
                    '每组最多 200 个成员',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
            foreach ($input['members'] as &$member) {
                if (!($member instanceof stdClass)) {
                    throw new BusinessException(
                        '成员必须是 JSON 对象',
                        ApiResponseConstant::VALIDATION_FAILED,
                    );
                }
                $member = $this->input('member', get_object_vars($member));
            }
            unset($member);
        }
        return $input;
    }

    protected function integerJson(mixed $value): bool
    {
        return is_int($value);
    }
    // 备注可为空，成员列表可为空，开关可为 false；null 仍须被原生规则拒绝。
    protected function requireText(mixed $value): bool
    {
        return is_string($value);
    }
    protected function requireBoolean(mixed $value): bool
    {
        return is_bool($value);
    }
    protected function requireArray(mixed $value): bool
    {
        return is_array($value);
    }
    protected function recordId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1;
    }
}
