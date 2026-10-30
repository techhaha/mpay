<?php
declare(strict_types=1);

namespace app\http\middleware;

use app\common\util\RequestContext;
use app\service\system\install\InstallationFiles;
use Closure;
use think\exception\HttpException;
use think\Request;
use think\Response;

/** HTTP 公共头及组件入口保护；覆盖安装期间在进入业务和 Session 之前暂停新请求。 */
final class RequestMiddleware
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly InstallationFiles $installation,
    ) {}

    /**
     * 下游抛异常时交给 ExceptionHandle 输出；本中间件不截获异常或推断支付结果。
     *
     * @param Closure(Request):Response $next 框架中间件管道的下一处理环节。
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 官方扩展自动注册的入口不含本站登录保护，只开放受限流及有效期控制的 /adminapi/captcha。
        if (preg_match('#^/captcha(?:/|$)#', '/' . ltrim($request->pathinfo(), '/'))) {
            throw new HttpException(404);
        }
        $path = trim($request->pathinfo(), '/');
        if (
            !in_array(
                $path,
                ['install', 'installapi/environment', 'installapi/check', 'installapi/install'],
                true,
            ) &&
            $this->installation->reinstalling()
        ) {
            throw new HttpException(503, '系统正在重新安装，请稍后再试');
        }
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
