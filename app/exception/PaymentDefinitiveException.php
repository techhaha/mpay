<?php
declare(strict_types=1);

namespace app\exception;

use app\common\constant\ApiResponseConstant;

/** 上游存在确定拒绝证据；仅表达异常分类，是否推进失败仍由生命周期服务核对原执行。 */
final class PaymentDefinitiveException extends BusinessException
{
    public function __construct(string $message = '本次支付执行被拒绝', ?\Throwable $previous = null)
    {
        parent::__construct($message, ApiResponseConstant::PAYMENT_REJECTED, [], $previous);
    }
}
