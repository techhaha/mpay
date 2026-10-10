<?php
declare(strict_types=1);

namespace app\service\payment\collection;

use app\service\payment\receipt\ReceiptScopeSet;
use app\exception\ReceiptCollectionException;
use think\Config;

/** 有界采集窗口与退避规则；完成时间游标不依赖平台某条流水的排序或本轮已翻页数量。 */
final readonly class ReceiptCollectionPolicy
{
    public int $leaseSeconds;
    public int $maxPages;
    public int $maxEvents;
    public int $pollSeconds;
    public int $disabledSeconds;
    private int $windowSeconds;
    private int $overlapSeconds;
    private int $initialSeconds;
    private int $historySeconds;
    private int $visibilitySeconds;
    private int $healthAgeSeconds;

    public function __construct(Config $config)
    {
        $this->leaseSeconds = $this->setting($config, 'lease_seconds', 45, 15, 300);
        $this->maxPages = $this->setting($config, 'max_pages', 20, 1, 100);
        $this->maxEvents = $this->setting($config, 'max_events', 5000, 1, 50000);
        $this->windowSeconds = $this->setting($config, 'window_seconds', 300, 1, 86400);
        $this->overlapSeconds = $this->setting($config, 'overlap_seconds', 60, 0, 3600);
        $this->initialSeconds = $this->setting($config, 'initial_lookback_seconds', 300, 1, 86400);
        $this->historySeconds = $this->setting($config, 'maximum_history_seconds', 86400, 60, 2592000);
        $this->visibilitySeconds = $this->setting($config, 'visibility_delay_seconds', 3, 0, 300);
        $this->pollSeconds = $this->setting($config, 'poll_interval_seconds', 3, 1, 3600);
        $this->disabledSeconds = $this->setting($config, 'disabled_retry_seconds', 300, 10, 86400);
        $healthAge = $config->get('mpay.receipt_health_max_age_seconds', 120);
        if (!is_int($healthAge) || $healthAge < 1 || $healthAge > 3600) {
            throw new \LogicException('采集健康有效期配置无效');
        }
        $this->healthAgeSeconds = $healthAge;
        if ($this->overlapSeconds >= $this->windowSeconds || $this->initialSeconds + $this->visibilitySeconds > $this->historySeconds
            || $this->windowSeconds > $this->historySeconds) {
            throw new \LogicException('采集窗口、重叠及历史上限不一致');
        }
    }

    /**
     * 同查询身份/插件/真实范围才能延续完成游标；凭证变化由会话仓储处理，不混入游标。
     * 超过工程历史界限必须显式显示缺口，不能把游标截断到最近窗口后声称恢复完整。
     *
     * @param ReceiptScopeSet $scopes 本轮完整覆盖的范围；不能把增加范围后的旧进度当成新范围已查完。
     * @param array<string,mixed>|null $cursor 只接受本服务生成的版本化完成游标，不接受平台分页 token。
     * @return array{from:int,to:int,cursor:array{version:int,identity:string,
     *     scopes:array<string,array{pay_type:string}>,coverage_from:int,completed_through:int}}
     * @throws ReceiptCollectionException 原范围变化、游标损坏或历史超限。
     */
    public function window(string $account, string $plugin, ReceiptScopeSet $scopes, ?array $cursor, \DateTimeImmutable $now): array
    {
        $ready = $now->getTimestamp() - $this->visibilitySeconds;
        if ($cursor === null) {
            $from = $ready - $this->initialSeconds;
            return ['from' => $from, 'to' => min($ready, $from + $this->windowSeconds),
                'cursor' => ReceiptCollectionCursor::create($account, $plugin, $scopes, $from, $from)];
        }
        $cursor = ReceiptCollectionCursor::read($account, $plugin, $cursor);
        if ($cursor['completed_through'] > $ready) {
            throw new ReceiptCollectionException('protocol_error');
        }
        if ($cursor['scopes'] !== $scopes->coverageScopes()) {
            throw new ReceiptCollectionException('scope_mismatch');
        }
        $from = max($cursor['coverage_from'], $cursor['completed_through'] - $this->overlapSeconds);
        if ($from < $now->getTimestamp() - $this->historySeconds) {
            throw new ReceiptCollectionException('history_gap');
        }
        $to = min($ready, $from + $this->windowSeconds);
        if ($to <= $from) {
            throw new ReceiptCollectionException('budget_exhausted', $this->pollSeconds);
        }
        return ['from' => $from, 'to' => $to, 'cursor' => $cursor];
    }

    /** 长期离线的旧窗口采集成功仍是追赶中，覆盖时间达到当前要求才显示已追赶完成。 */
    public function caughtUp(int $completedThrough, \DateTimeImmutable $now): bool
    {
        return $completedThrough >= $now->getTimestamp() - $this->healthAgeSeconds && $completedThrough <= $now->getTimestamp();
    }

    /** 分类不触碰支付状态；只有明确认证失效才由调用方清理会话。 */
    public function failureStatus(string $reason): int
    {
        return match ($reason) {
            'configuration_conflict', 'authentication_expired' => \app\common\constant\ReceiptRuntimeConstant::AUTH_REQUIRED, 'rate_limited' => \app\common\constant\ReceiptRuntimeConstant::RATE_LIMITED,
            'scope_mismatch' => \app\common\constant\ReceiptRuntimeConstant::SCOPE_ERROR, 'protocol_error', 'pagination_incomplete' => \app\common\constant\ReceiptRuntimeConstant::PROTOCOL_ERROR,
            'history_gap' => \app\common\constant\ReceiptRuntimeConstant::HISTORY_GAP, default => \app\common\constant\ReceiptRuntimeConstant::RETRYING,
        };
    }

    /** 秒数有上限，单个失败账号不阻塞其他账号；限流明确等待优先，确定范围/协议错误低频复核。 */
    public function retryDelay(ReceiptCollectionException $failure, int $previousFailures): int
    {
        if ($failure->retryAfterSeconds !== null) {
            return $failure->retryAfterSeconds;
        }
        if (in_array($failure->reasonCode, ['configuration_conflict', 'scope_mismatch', 'protocol_error', 'history_gap', 'pagination_incomplete'], true)) {
            return $this->disabledSeconds;
        }
        return min(300, 5 * (2 ** min(6, max(0, $previousFailures))));
    }

    private function setting(Config $config, string $name, int $default, int $minimum, int $maximum): int
    {
        $value = $config->get('receipt.' . $name, $default);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \LogicException('采集预算配置无效：' . $name);
        }
        return $value;
    }
}
