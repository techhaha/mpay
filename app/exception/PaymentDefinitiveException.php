<?php
declare(strict_types=1);

namespace app\exception;

use app\common\constant\ApiResponseConstant;
use Throwable;

/** 上游存在确定拒绝证据；插件须提供已脱敏说明，是否推进失败仍由服务核对原执行。 */
final class PaymentDefinitiveException extends BusinessException
{
    public function __construct(
        string $message = '本次支付执行被拒绝',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, ApiResponseConstant::PAYMENT_REJECTED, [], $previous);
    }
}
