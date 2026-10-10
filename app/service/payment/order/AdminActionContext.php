<?php
declare(strict_types=1);

namespace app\service\payment\order;

/** 管理动作的显式操作者上下文，管理员身份及会话版本只能来自服务端认证，不能取自请求正文。 */
final readonly class AdminActionContext
{
    /**
     * @param string $requestId 服务端请求关联号。
     * @param string $reason 人工动作原因，作为审计原样保留，不写凭证或完整平台报文。
     */
    public function __construct(
        public string $adminId,
        public int $sessionVersion,
        public string $requestId,
        public string $reason,
    ) {
        if (!preg_match('/^[1-9][0-9]*$/D', $adminId) || $sessionVersion < 1
            || !preg_match('/^[a-f0-9]{32}$/D', $requestId) || trim($reason) === ''
            || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason, 'UTF-8') > 512) {
            throw new \InvalidArgumentException('管理操作缺少有效身份或原因');
        }
    }
}
