<?php
declare(strict_types=1);

namespace app\http\middleware;

use app\common\util\RequestContext;
use think\App;
use think\Request;
use think\Response;

/** HTTP 入口按框架请求协议设置会话及公共响应头；不重复实现地址、协议或代理判断。 */
final class RequestMiddleware
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly App $app,
    ) {
    }

    /**
     * 下游抛异常时交给 ExceptionHandle 输出；本中间件不截获异常或推断支付结果。
     *
     * @param \Closure(Request): Response $next 框架中间件管道的下一处理环节。
     */
    public function handle(Request $request, \Closure $next): Response
    {
        // 必须早于路由的 SessionInit：Cookie/Session 由框架延迟创建，配置不冻结在安装时。
        $secure = $request->isSsl();
        $this->app->config->set(['secure' => $secure], 'cookie');
        // HTTPS 身份不与明文 HTTP 共用 Cookie 或文件会话，切换协议可分别登录。
        $this->app->config->set([
            'name' => $secure ? '__Host-mpay_session' : 'mpay_session',
            'path' => $this->app->getRuntimePath() . 'session/' . ($secure ? 'https/' : 'http/'),
        ], 'session');
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
