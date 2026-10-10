<?php
declare(strict_types=1);

namespace app\http\middleware;

use app\common\util\RequestContext;
use think\Request;
use think\Response;

/** HTTP 公共中间件只设置关联号和响应头；故障及业务审计由各自边界记录，不逐次写收银台轮询成功日志。 */
final class RequestMiddleware
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    /**
     * 下游抛异常时交给 ExceptionHandle 输出；本中间件不截获异常或推断支付结果。
     *
     * @param \Closure(Request): Response $next 框架中间件管道的下一处理环节。
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);
        return $response->header([
            'X-Request-Id' => $this->context->id(),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ]);
    }
}
