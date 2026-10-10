<?php
declare(strict_types=1);

namespace app\service\payment\notify;

use Psr\Http\Message\ResponseInterface;
use think\Config;

/** 通知自动处理期限、退避和 ACK 的唯一规则；不查询数据库，不改变付款成功事实。 */
final readonly class NotificationPolicy
{
    /** @var list<int> 第一次失败后的各次等待秒数；列表长度加一是自动尝试上限。 */
    private array $delays;
    public int $leaseSeconds;
    private int $deadlineSeconds;

    public function __construct(Config $config)
    {
        $delays = $config->get('notify.retry_delays_seconds', [10, 30, 120, 600, 1800, 7200, 21600]);
        $lease = $config->get('notify.lease_seconds', 30);
        $deadline = $config->get('notify.automatic_deadline_seconds', 86400);
        $timeout = $config->get('http.timeout_seconds', 10);
        if (!is_array($delays) || !array_is_list($delays) || count($delays) > 31
            || !is_int($lease) || $lease < 10 || $lease > 300
            || !is_int($deadline) || $deadline < 60 || $deadline > 604800
            || !is_int($timeout) || $timeout < 1 || $timeout > 60 || $timeout + 5 > $lease) {
            throw new \LogicException('通知期限、租约或 HTTP 超时配置不一致');
        }
        foreach ($delays as $delay) {
            if (!is_int($delay) || $delay < 1 || $delay > 86400) {
                throw new \LogicException('通知退避间隔必须为有界正整数秒');
            }
        }
        $this->delays = $delays;
        $this->leaseSeconds = $lease;
        $this->deadlineSeconds = $deadline;
    }

    /** 已使用次数包括初次认领和中断尝试；达到期限的那个时刻不再自动发送。 */
    public function canAttempt(\DateTimeImmutable $created, int $usedAttempts, \DateTimeImmutable $now): bool
    {
        return $usedAttempts >= 0 && $usedAttempts < count($this->delays) + 1
            && $now < $created->modify('+' . $this->deadlineSeconds . ' seconds');
    }

    /**
     * 按本次完成时间安排下一次；不会为了追赶逾期计划而在进程恢复时连续突发重发。
     *
     * @return \DateTimeImmutable|null null 表示次数或自动期限用尽，需要后续有审计的人工处理。
     */
    public function nextAttempt(\DateTimeImmutable $created, int $usedAttempts, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($usedAttempts < 1 || !$this->canAttempt($created, $usedAttempts, $now)) {
            return null;
        }
        $next = $now->modify('+' . $this->delays[$usedAttempts - 1] . ' seconds');
        return $this->canAttempt($created, $usedAttempts, $next) ? $next : null;
    }

    /** ACK 固定为成功 HTTP 状态和小写 success，仅兼容首尾 SP/HT/CR/LF，不接受 BOM/NUL 或 HTML。 */
    public function acknowledged(ResponseInterface $response): bool
    {
        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
            && trim((string) $response->getBody(), " \t\r\n") === 'success';
    }
}
