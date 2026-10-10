<?php
declare(strict_types=1);

namespace app\http\api\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Validate;

/** 收银台字段及场景校验；只接收访问凭证和方式选择，不接收客户端金额或成功声明。 */
final class CashierValidator extends Validate
{
    /** @var array<string,array> 编号按不透明标识查询；生成格式由 BusinessNumber 负责，访问仍核对独立随机凭证。 */
    protected $rule = [
        'biz_no' => ['require', 'string', 'regex' => '/^B[A-Za-z0-9]{1,63}$/D'],
        'token' => ['require', 'string', 'regex' => '/^[a-f0-9]{64}$/D'],
        'type' => ['require', 'string', 'regex' => \app\common\validation\PaymentTypeCode::PATTERN],
    ];
    protected $scene = ['identity' => ['biz_no', 'token'], 'selection' => ['type']];

    /**
     * @param array<string,mixed> $input 不可信的查询/JSON 参数。
     * @return array{biz_no:string,token:string}
     * @throws BusinessException 身份格式错误统一返回 403，不区分哪个秘密字段错误。
     */
    public function identity(array $input): array
    {
        if (!(clone $this)->scene('identity')->failException(false)->check($input)) {
            throw new BusinessException('订单访问凭证无效', ApiResponseConstant::FORBIDDEN);
        }
        return ['biz_no' => $input['biz_no'], 'token' => $input['token']];
    }

    /**
     * 写操作拒绝未知字段，再按身份、方式顺序验证，保留 403/422 的原协议边界。
     *
     * @param array<string,mixed> $input
     * @return array{biz_no:string,token:string,type?:string}
     */
    public function action(array $input, bool $select): array
    {
        $allowed = $select ? ['biz_no', 'token', 'type'] : ['biz_no', 'token'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new BusinessException('收银台操作包含未允许的字段', ApiResponseConstant::VALIDATION_FAILED);
        }
        $identity = $this->identity($input);
        if ($select) {
            if (!(clone $this)->scene('selection')->failException(false)->check($input)) {
                throw new BusinessException('请选择支持的支付方式', ApiResponseConstant::VALIDATION_FAILED);
            }
            $identity['type'] = $input['type'];
        }
        return $identity;
    }
}
