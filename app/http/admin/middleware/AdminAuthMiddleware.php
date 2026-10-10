<?php
declare(strict_types=1);

namespace app\http\admin\middleware;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\service\system\access\AdminAuthService;
use app\service\system\access\AdminSessionService;
use think\Request;
use think\Response;

/** 后台路由组的认证入口：必须先初始化 Session；每个请求读取当前身份版本，支付/设备路由不挂载。 */
final class AdminAuthMiddleware
{
    public function __construct(private readonly AdminSessionService $sessions, private readonly AdminAuthService $auth)
    {
    }

    /**
     * 页面失效跳到固定登录页，API 失效交给统一异常处理返回 401 JSON，不能返回登录 HTML。
     * 已认证信息放入框架 middleware 上下文；业务变更仍须在事务锁内复核版本，消除改密竞态。
     *
     * @param \Closure(Request):Response $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $stored = $this->sessions->identity();
        $identity = $stored === null ? null : $this->auth->current($stored['id'], $stored['session_version']);
        if ($identity === null) {
            if ($stored !== null) {
                $this->sessions->invalidate();
            }
            if (str_starts_with('/' . ltrim($request->pathinfo(), '/'), '/adminapi/')) {
                throw new BusinessException('登录已失效，请重新登录', ApiResponseConstant::UNAUTHENTICATED);
            }
            return redirect('/admin/login', 303);
        }
        $request->withMiddleware(['admin_identity' => $identity]);
        return $next($request);
    }
}
