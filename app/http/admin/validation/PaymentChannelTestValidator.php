<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\common\util\Money;
use app\exception\BusinessException;
use InvalidArgumentException;
use think\Validate;

/** 指定应用和当前通道发起付款；PID/KEY 从服务端读取，不允许传入路由、成功状态或回调地址。 */
final class PaymentChannelTestValidator extends Validate
{
    protected $rule = [
        'channel_id' => ['require', 'string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'app_id' => ['require', 'string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'money' => ['require', 'string', 'regex' => '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D'],
        'request_key' => ['require', 'string', 'regex' => '/^[a-f0-9]{32}$/D'],
    ];

    /**
     * 核对字段白名单和金额后，返回供建单服务使用的明确输入；PID 与凭证不从表单接收。
     *
     * @return array{channel_id:string,app_id:string,amount:int,request_key:string} amount 单位为分。
     * @throws BusinessException 字段缺失、存在额外参数、金额或数据库 ID 越界。
     */
    public function input(array $input): array
    {
        if (
            array_diff(array_keys($input), array_keys($this->rule)) !== [] ||
            !(clone $this)->failException(false)->check($input)
        ) {
            throw new BusinessException(
                '请填写有效测试金额',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        try {
            $amount = Money::parse($input['money']);
        } catch (InvalidArgumentException) {
            throw new BusinessException(
                '测试金额超出允许范围',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        if (
            $amount < 1 ||
            (strlen($input['channel_id']) === 20 &&
                strcmp($input['channel_id'], '18446744073709551615') > 0) ||
            (strlen($input['app_id']) === 20 &&
                strcmp($input['app_id'], '18446744073709551615') > 0)
        ) {
            throw new BusinessException(
                '测试金额或通道编号无效',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        return [
            'channel_id' => $input['channel_id'],
            'app_id' => $input['app_id'],
            'amount' => $amount,
            'request_key' => $input['request_key'],
        ];
    }
}
