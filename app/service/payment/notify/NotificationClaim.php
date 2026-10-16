<?php
declare(strict_types=1);

namespace app\service\payment\notify;

/** 已提交的本次通知认领快照；只携带冻结内容，不能用当前商户配置重建旧通知。 */
final readonly class NotificationClaim
{
    /** @param array<string|int,string> $parameters 原目标 query 与成功参数合并后的完整冻结签名内容。 */
    public function __construct(
        public string $notifyNo,
        public string $token,
        public int $attempt,
        public string $target,
        public array $parameters,
        // 自动预算起点：常规为创建时间，人工暂缓任务为首次解除时间。
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $leaseUntil,
        // 人工授权只覆盖本次网络尝试；失败或崩溃后不重新开启一轮自动重试。
        public bool $manual = false,
    ) {}
}
