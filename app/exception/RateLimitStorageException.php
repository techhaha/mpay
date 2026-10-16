<?php
declare(strict_types=1);

namespace app\exception;

use RuntimeException;
use Throwable;

/** 短期保护存储不可用时拒绝本次执行；不清零计数、不伪造支付成功，公开响应不含路径。 */
final class RateLimitStorageException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('请求保护暂不可用，请稍后重试', 0, $previous);
    }
}
