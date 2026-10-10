<?php
declare(strict_types=1);

namespace app\service\system\access;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant as Code;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\model\admin\AdminUser;
use app\repository\system\AdminOperationLogRepository;
use app\repository\system\AdminUserRepository;

/**
 * 单管理员认证、改密与身份版本校验；不读取 HTTP/Session，返回的身份由入口写入框架会话。
 * 管理员锁统一失败计数、密码变更及审计的顺序；拒绝结果先提交审计，再在事务外抛公开异常。
 */
final class AdminAuthService extends BaseService
{
    // 工程默认：同一服务端观测 IP，登录/改密分别在最近十分钟允许十次密码失败。
    // 不按提交的用户名分桶，避免修改用户名绕过；限流不永久停用管理员账号。
    private const FAILURE_LIMIT = 10;
    private const FAILURE_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly AdminUserRepository $admins,
        private readonly AdminOperationLogRepository $logs,
        private readonly Clock $clock,
    ) {
    }

    /**
     * 成功只证明当前凭据有效；HTTP 会话必须在本事务成功返回后重建，不能提前发放身份。
     * 用户名不存在、账号停用和密码错误使用同一公开拒绝；不存在初始化记录时明确要求完成安装。
     *
     * @return array{id:string,username:string,session_version:int} 不含密码摘要的服务端身份。
     * @throws BusinessException 认证失败、十分钟失败次数超限或后台尚未初始化。
     */
    public function login(string $username, #[\SensitiveParameter] string $password, string $requestId, string $clientIp): array
    {
        $clientIp = $this->clientIp($clientIp);
        $result = $this->transaction(function () use ($username, $password, $requestId, $clientIp): array|string {
            $admin = $this->admins->lockSingleton();
            if ($admin === null) {
                throw new BusinessException('后台尚未初始化，请先完成安装', Code::SERVICE_UNAVAILABLE);
            }
            if ($this->limited(null, 'admin.login', $clientIp)) {
                return 'limited';
            }
            // 即使用户名不匹配也校验同一密码摘要，避免按用户名直接跳过昂贵校验。
            $passwordValid = password_verify($password, $admin['password_hash']);
            if (!$passwordValid || !hash_equals($admin['username'], $username) || (int) $admin['status'] !== 1) {
                $this->audit(null, 'admin.login', 'rejected', $requestId, $clientIp);
                return 'rejected';
            }
            $identity = $this->identity($admin);
            if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                // 摘要算法维护不改变实际密码，不撤销其他有效会话；真正改密才递增版本。
                $this->admins->updatePassword($identity['id'], $identity['session_version'], password_hash($password, PASSWORD_DEFAULT),
                    $identity['session_version'], $this->clock->now());
            }
            $this->audit($identity['id'], 'admin.login', 'accepted', $requestId, $clientIp,
                ['session_version' => $identity['session_version']]);
            return $identity;
        });
        if ($result === 'limited') {
            throw new BusinessException('尝试次数过多，请十分钟后重试', Code::TOO_MANY_REQUESTS);
        }
        if ($result === 'rejected') {
            throw new BusinessException('用户名或密码错误', Code::UNAUTHENTICATED);
        }
        return $result;
    }

    /**
     * 每个受保护请求核对数据库当前状态，不缓存身份版本；业务写操作仍须在自身事务内再次授权。
     *
     * @return array{id:string,username:string,session_version:int}|null 已停用、删除或改密返回 null。
     */
    public function current(string $adminId, int $sessionVersion): ?array
    {
        $admin = $this->admins->find($adminId);
        return $admin !== null && (int) $admin['status'] === 1 && (int) $admin['session_version'] === $sessionVersion
            ? $this->identity($admin) : null;
    }

    /**
     * 修改密码与递增 session_version、审计同事务，提交后所有旧版本会话失效，包括发起本次请求的会话。
     * 密码字段先经入口验证；此处另保护 bcrypt 的字节上限，防止未来内部调用产生静默截断。
     *
     * @param string $adminId 来自已认证的服务器会话，不能取请求正文。
     * @param int $sessionVersion 当前请求携带的身份版本，锁内仍与数据库复核。
     * @param string $requestId 服务端生成的审计关联号。
     * @throws BusinessException 会话失效、原密码错误、限流或新密码不符合存储约束。
     */
    public function changePassword(string $adminId, int $sessionVersion, string $requestId, #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword, string $clientIp): void
    {
        if (!mb_check_encoding($newPassword, 'UTF-8') || mb_strlen($newPassword, 'UTF-8') < 12
            || strlen($newPassword) > 72 || str_contains($newPassword, "\0")) {
            throw new BusinessException('新密码至少 12 个字符、最多 72 字节，不能含空字符', Code::VALIDATION_FAILED);
        }
        $clientIp = $this->clientIp($clientIp);
        $result = $this->transaction(function () use ($adminId, $sessionVersion, $requestId, $currentPassword, $newPassword, $clientIp): string {
            $admin = $this->authoriseLocked($adminId, $sessionVersion);
            if ($this->limited($adminId, 'admin.password', $clientIp)) {
                return 'limited';
            }
            if (!password_verify($currentPassword, $admin['password_hash'])) {
                $this->audit($adminId, 'admin.password', 'rejected', $requestId, $clientIp);
                return 'rejected';
            }
            if (hash_equals($currentPassword, $newPassword)) {
                $this->audit($adminId, 'admin.password', 'unchanged', $requestId, $clientIp, ['session_version' => $sessionVersion]);
                return 'unchanged';
            }
            if ($sessionVersion >= 4294967295) {
                throw new BusinessException('认证版本已达到上限，请通过维护流程处理', Code::CONFLICT);
            }
            $this->admins->updatePassword($adminId, $sessionVersion, password_hash($newPassword, PASSWORD_DEFAULT),
                $sessionVersion + 1, $this->clock->now());
            $this->audit($adminId, 'admin.password', 'changed', $requestId, $clientIp,
                ['old_version' => $sessionVersion, 'new_version' => $sessionVersion + 1]);
            return 'changed';
        });
        if ($result === 'limited') {
            throw new BusinessException('尝试次数过多，请十分钟后重试', Code::TOO_MANY_REQUESTS);
        }
        if ($result === 'rejected') {
            throw new BusinessException('当前密码不正确', Code::FORBIDDEN);
        }
        if ($result === 'unchanged') {
            throw new BusinessException('新密码不能与当前密码相同', Code::VALIDATION_FAILED);
        }
    }

    /**
     * 单管理员退出统一撤销已有登录版本；HTTP 入口另行销毁当前 Session。
     * 原生文件驱动只在读写时锁文件，并发旧请求可能随后写回旧 Session；数据库版本确保它不能重新获得授权。
     * 这会同时退出其他设备，界面必须明确说明；不尝试终止已经开始的只读响应。
     */
    public function logout(string $adminId, int $sessionVersion, string $requestId, string $clientIp): void
    {
        $clientIp = $this->clientIp($clientIp);
        $this->transaction(function () use ($adminId, $sessionVersion, $requestId, $clientIp): void {
            $this->authoriseLocked($adminId, $sessionVersion);
            if ($sessionVersion >= 4294967295) {
                throw new BusinessException('认证版本已达到上限，请通过维护流程处理', Code::CONFLICT);
            }
            $this->admins->updateSessionVersion($adminId, $sessionVersion, $sessionVersion + 1, $this->clock->now());
            $this->audit($adminId, 'admin.logout', 'accepted', $requestId, $clientIp,
                ['old_version' => $sessionVersion, 'new_version' => $sessionVersion + 1]);
        });
    }

    /** 管理员锁必须先于其他业务锁；认证动作仅在这一行及审计表内工作，不调用外部平台。 */
    private function authoriseLocked(string $adminId, int $sessionVersion): AdminUser
    {
        $admin = $this->admins->lock($adminId);
        if ($admin === null || (int) $admin['status'] !== 1 || (int) $admin['session_version'] !== $sessionVersion) {
            throw new BusinessException('管理员会话已失效，请重新登录', Code::UNAUTHENTICATED);
        }
        return $admin;
    }

    /**
     * 敏感业务动作的密码复核；失败按管理员统一计数，不因调用入口或来源 IP 改变而得到无限猜测次数。
     * 独立短事务提交失败审计后返回；真正业务事务仍须锁定同一身份版本，不能把复核当作永久授权。
     */
    public function verifyPassword(string $adminId, int $sessionVersion, string $requestId, #[\SensitiveParameter] string $password): void
    {
        $result = $this->transaction(function () use ($adminId, $sessionVersion, $requestId, $password): string {
            $admin = $this->authoriseLocked($adminId, $sessionVersion);
            if ($this->limited($adminId, 'admin.reauthenticate', null)) { return 'limited'; }
            if (!password_verify($password, $admin['password_hash'])) {
                $this->audit($adminId, 'admin.reauthenticate', 'rejected', $requestId, null, ['session_version' => $sessionVersion]);
                return 'rejected';
            }
            return 'accepted';
        });
        if ($result === 'limited') { throw new BusinessException('密码复核次数过多，请十分钟后重试', Code::TOO_MANY_REQUESTS); }
        if ($result === 'rejected') { throw new BusinessException('管理员确认密码无效', Code::FORBIDDEN); }
    }

    /** 在管理员行锁内检查，拒绝记录提交前其他认证不能越过同一失败预算。 */
    private function limited(?string $adminId, string $action, ?string $clientIp): bool
    {
        $since = $this->clock->now()->modify('-' . self::FAILURE_WINDOW_SECONDS . ' seconds');
        return $this->logs->rejectedAuthenticationCount($adminId, $action, $clientIp, $since, self::FAILURE_LIMIT) >= self::FAILURE_LIMIT;
    }

    /**
     * 审计只保存已确定身份、固定结果和认证版本；不记录提交的用户名、密码、Cookie 或会话标识。
     *
     * @param array{session_version?:int,old_version?:int,new_version?:int} $versions 本服务构造的版本摘要。
     */
    private function audit(?string $adminId, string $action, string $result, string $requestId, ?string $clientIp, array $versions = []): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
            throw new \InvalidArgumentException('认证审计缺少服务端请求编号');
        }
        $this->logs->record(['admin_user_id' => $adminId, 'request_id' => $requestId, 'action' => $action,
            'target_type' => 'admin_user', 'target_id' => $adminId, 'result_code' => $result,
            'summary_json' => json_encode((object) $versions, JSON_THROW_ON_ERROR), 'client_ip' => $clientIp]);
    }

    /** @return array{id:string,username:string,session_version:int} 内部身份版本不能接受浏览器覆盖。 */
    private function identity(AdminUser $admin): array
    {
        return ['id' => $admin['id'], 'username' => $admin['username'], 'session_version' => (int) $admin['session_version']];
    }

    /** IP 只能由入口的服务器连接信息传入，规范化 IPv6 表示，不能以转发头改变限流桶。 */
    private function clientIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('认证缺少有效连接地址');
        }
        return inet_ntop(inet_pton($ip));
    }
}
