<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\http\admin\validation\AdminAuthValidator;
use app\service\system\access\AdminAuthService;
use app\service\system\access\AdminSessionService;
use think\Response;

/** 后台认证 HTTP 边界；密码校验/审计在服务中，会话与公开响应留在入口，不发放可供易支付使用的身份。 */
final class AdminAuthController extends BaseAdminController
{
    /** 登录审计提交后才重建 Session；返回的新 token 只用于本站表单，不允许客户端指定跳转目标。 */
    public function login(
        AdminAuthValidator $validator,
        AdminAuthService $auth,
        AdminSessionService $sessions,
    ): Response {
        $input = $validator->validateInput('login', $this->jsonBody());
        $identity = $auth->login(
            $input['username'],
            $input['password'],
            $this->context->id(),
            $this->clientIp(),
        );
        $sessions->establish($identity['id'], $identity['session_version']);
        return $this->success(
            [
                'user' => ['id' => $identity['id'], 'username' => $identity['username']],
                'csrf_token' => $sessions->csrfToken(),
                'redirect' => '/admin/dashboard',
            ],
            '登录成功',
        );
    }

    /** 由受保护 GET 路由调用，只输出公开账户名和当前表单 token，不输出认证版本或密码摘要。 */
    public function me(AdminSessionService $sessions): Response
    {
        $identity = $this->identity();
        return $this->success([
            'user' => ['id' => $identity['id'], 'username' => $identity['username']],
            'csrf_token' => $sessions->csrfToken(),
        ]);
    }

    /** 改密事务成功后销毁当前会话；如果响应丢失，旧版本仍由数据库拒绝，不能重放写操作。 */
    public function password(
        AdminAuthValidator $validator,
        AdminAuthService $auth,
        AdminSessionService $sessions,
    ): Response {
        $identity = $this->identity();
        $input = $validator->validateInput('password', $this->jsonBody());
        $auth->changePassword(
            $identity['id'],
            $identity['session_version'],
            $this->context->id(),
            $input['current_password'],
            $input['new_password'],
            $this->clientIp(),
        );
        $sessions->invalidate();
        return $this->success(null, '密码已更新，请重新登录');
    }

    /** 退出即使审计设施失败也撤销本地会话；审计失败继续返回错误，不能声称审计已成功。 */
    public function logout(AdminAuthService $auth, AdminSessionService $sessions): Response
    {
        if ($this->jsonBody() !== []) {
            throw new BusinessException(
                '退出操作不接受额外字段',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $identity = $this->identity();
        try {
            $auth->logout(
                $identity['id'],
                $identity['session_version'],
                $this->context->id(),
                $this->clientIp(),
            );
        } finally {
            $sessions->invalidate();
        }
        return $this->success(null, '已退出所有登录');
    }

    /** 只取服务器实际连接地址；反向代理须在 Web 服务端正确设置 REMOTE_ADDR，不信任浏览器转发头。 */
    private function clientIp(): string
    {
        return (string) $this->request->server('REMOTE_ADDR', '');
    }
}
