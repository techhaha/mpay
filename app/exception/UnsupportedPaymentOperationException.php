<?php
declare(strict_types=1);

namespace app\exception;

use app\common\constant\ApiResponseConstant;

/** 插件没有请求的操作能力，不能用空结果或伪造成功代替能力拒绝。 */
final class UnsupportedPaymentOperationException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('当前收款方式不支持此操作', ApiResponseConstant::OPERATION_UNSUPPORTED);
    }
}
