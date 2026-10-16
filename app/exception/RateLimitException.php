<?php
declare(strict_types=1);

namespace app\exception;

use RuntimeException;

/** 请求在业务执行前被限流；由 HTTP 异常边界转换协议，不能被易支付业务拒绝捕获为 HTTP 200。 */
final class RateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('操作过于频繁，请 ' . $retryAfter . ' 秒后重试');
    }
}
