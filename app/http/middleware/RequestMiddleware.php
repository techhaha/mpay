<?php
declare(strict_types=1);

namespace app\http\middleware;

use app\common\util\RequestContext;
use think\Request;
use think\Response;

/** HTTP 入口统一附加请求关联号与公共响应头；会话由框架按固定配置初始化。 */
final class RequestMiddleware
{
    public function __construct(
        private readonly RequestContext $context,
    ) {
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
