<?php
declare(strict_types=1);

namespace app\http\install\middleware;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\service\system\install\InstallationFiles;
use think\Request;
use think\Response;

/** 安装入口封闭检查；安装锁优先于框架 Session 初始化，不判断访问协议。 */
final class InstallMiddleware
{
    public function __construct(private readonly InstallationFiles $files) {}

    /** @param \Closure(Request):Response $next 后续依次 Session、跨站标识/正文、CSRF 和安装控制器。 */
    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->files->completed()) {
            if (ltrim($request->pathinfo(), '/') === 'install' && in_array($request->method(true), ['GET', 'HEAD'], true)) { return redirect('/admin/login', 303); }
            throw new BusinessException('系统已经安装，安装入口已关闭', ApiResponseConstant::CONFLICT);
        }
        return $next($request);
    }
}
