<?php
declare(strict_types=1);

namespace app\service\system\audit;

/** 常规配置不要求手填原因；凭证重置等特殊操作可携带原因。配置管理的服务器操作者上下文；CLI 信任本机执行权限，Web 身份仍须在事务内复核管理员会话。 */
final readonly class ConfigurationActionContext
{
    private function __construct(
        public ?string $adminId,
        public ?int $sessionVersion,
        public string $requestId,
        public ?string $reason,
        public ?string $clientIp,
        public string $origin,
    ) {
        if (
            !preg_match('/^[a-f0-9]{32}$/D', $requestId) ||
            ($reason !== null &&
                (trim($reason) === '' ||
                    mb_strlen($reason, 'UTF-8') > 500 ||
                    !mb_check_encoding($reason, 'UTF-8') ||
                    str_contains($reason, "\0"))) ||
            ($clientIp !== null && filter_var($clientIp, FILTER_VALIDATE_IP) === false)
        ) {
            throw new \InvalidArgumentException('配置操作上下文无效');
        }
    }

    /** 仅本机 CLI 可创建安装/维护操作者；不会把环境变量或请求字段当成 CLI 身份。 */
    public static function cli(?string $reason = null): self
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('CLI 配置操作不能从 Web 入口创建');
        }
        return new self(null, null, bin2hex(random_bytes(16)), $reason, null, 'cli');
    }

    /**
     * 身份必须来自服务端已认证 Session，禁止用 HTTP 参数直接构造。
     * 服务仍会在写事务中锁管理员复核版本，保证改密/退出撤销后旧上下文不能继续修改。
     */
    public static function administrator(
        string $adminId,
        int $sessionVersion,
        string $requestId,
        ?string $reason,
        ?string $clientIp,
    ): self {
        if (
            !preg_match('/^[1-9][0-9]{0,19}$/D', $adminId) ||
            $sessionVersion < 1 ||
            (strlen($adminId) === 20 && strcmp($adminId, '18446744073709551615') > 0)
        ) {
            throw new \InvalidArgumentException('配置操作管理员身份无效');
        }
        return new self($adminId, $sessionVersion, $requestId, $reason, $clientIp, 'admin');
    }
}
