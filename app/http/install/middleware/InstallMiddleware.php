<?php
declare(strict_types=1);

namespace app\http\install\middleware;

use app\common\constant\ApiResponseConstant;
use app\common\util\SiteUrl;
use app\exception\BusinessException;
use app\service\system\install\InstallationFiles;
use think\App;
use think\Request;
use think\Response;

/** 安装组专用引导：安装锁优先于 Session；初装 HTTP 的 Cookie 按当前协议设置，不影响其他路由。 */
final class InstallMiddleware
{
    public function __construct(private readonly App $app, private readonly InstallationFiles $files, private readonly SiteUrl $site) {}

    /** @param \Closure(Request):Response $next 后续依次 Session、同源正文、CSRF 和安装控制器。 */
    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->files->completed()) {
            if (ltrim($request->pathinfo(), '/') === 'install' && in_array($request->method(true), ['GET', 'HEAD'], true)) { return redirect('/admin/login', 303); }
            throw new BusinessException('系统已经安装，安装入口已关闭', ApiResponseConstant::CONFLICT);
        }
        $cookie = $this->app->config->get('cookie');
        $cookie['secure'] = str_starts_with($this->site->origin(), 'https://');
        $this->app->config->set($cookie, 'cookie');
        return $next($request);
    }
}
