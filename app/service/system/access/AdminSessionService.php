<?php
declare(strict_types=1);

namespace app\service\system\access;

use think\Session;

/**
 * 后台 HTTP 会话和 CSRF token 生命周期，依赖已由 SessionInit 初始化的框架 Session。
 * 不验证密码或授权管理动作；认证入口须先完成身份校验，再调用 establish。
 */
final class AdminSessionService
{
    public function __construct(private readonly Session $session)
    {
    }

    /** 后台与同源收银台复用会话 token，不为收银台建立管理员身份，也不使其他标签页表单失效。 */
    public function csrfToken(): string
    {
        $token = $this->session->get('csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf_token', $token);
        }
        return $token;
    }

    /**
     * 只读取服务器会话保存的身份，不从 Cookie 正文、表单或请求头读取管理员 ID/版本。
     * 返回后仍须由 AdminAuthService 与数据库核对；这个结构本身不是授权结论。
     *
     * @return array{id:string,session_version:int}|null
     */
    public function identity(): ?array
    {
        $identity = $this->session->get('admin');
        if (!is_array($identity) || !is_string($identity['id'] ?? null)
            || !preg_match('/^[1-9][0-9]{0,19}$/D', $identity['id'])
            || !is_int($identity['session_version'] ?? null) || $identity['session_version'] < 1) {
            return null;
        }
        return ['id' => $identity['id'], 'session_version' => $identity['session_version']];
    }

    /**
     * 已验证身份后建立后台会话，销毁登录前的 Session ID 和 CSRF token，防止会话固定。
     *
     * @param int $sessionVersion 当前身份版本；后续鉴权需与数据库比对才能使旧登录失效。
     */
    public function establish(string $adminId, int $sessionVersion): void
    {
        $this->session->clear();
        $this->session->regenerate(true);
        $this->session->set('admin', ['id' => $adminId, 'session_version' => $sessionVersion]);
        $this->csrfToken();
    }

    /** 只撤销当前会话；使全部会话失效还需认证流程更新并检查 session_version。 */
    public function invalidate(): void
    {
        $this->session->clear();
        $this->session->regenerate(true);
    }
}
