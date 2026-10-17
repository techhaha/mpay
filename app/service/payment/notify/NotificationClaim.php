<?php
declare(strict_types=1);

namespace app\service\payment\notify;

use DateTimeImmutable;

/** 已提交的认领快照；target 为按冻结参数构造的完整 URL，不使用当前商户配置。 */
final readonly class NotificationClaim
{
    /** @param string $signature 原签名仅用于遮蔽商户响应中的回显。 */
    public function __construct(
        public string $notifyNo,
        public string $token,
        public int $attempt,
        public string $target,
        public string $signature,
        // 自动预算起点：常规为创建时间，人工暂缓任务为首次解除时间。
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $leaseUntil,
        // 人工授权只覆盖本次网络尝试；失败或崩溃后不重新开启一轮自动重试。
        public bool $manual = false,
    ) {}
}
