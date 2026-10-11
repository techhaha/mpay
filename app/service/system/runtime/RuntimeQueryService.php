<?php
declare(strict_types=1);

namespace app\service\system\runtime;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\constant\NotifyConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\exception\ReceiptCollectionException;
use app\model\payment\PaymentPluginConf;
use app\repository\system\RuntimeDiagnosticRepository;
use app\service\payment\collection\ReceiptCollectionCursor;
use Composer\InstalledVersions;
use DateTimeImmutable;

/** 运行维护的白名单读视图，组合进程心跳、持久采集记录与通知计数；不触发采集、清理、迁移或平台登录。 */
final class RuntimeQueryService extends BaseService
{
    private const STATUSES = \app\common\constant\ReceiptRuntimeConstant::LABELS;
    private const REASONS = [
        'configuration_conflict' => '同一登录账号的认证配置不一致，请核对相关通道', 'authentication_expired' => '平台明确拒绝认证', 'network_error' => '网络请求未完成', 'rate_limited' => '平台要求降低请求频率',
        'scope_mismatch' => '来源与授权收款范围不符', 'protocol_error' => '来源数据不符合约定', 'history_gap' => '历史窗口未完整覆盖',
        'budget_exhausted' => '本批采集已达到处理上限，将在下一批继续', 'configuration_changed' => '配置已更新，等待重新检查', 'unverified' => '来源尚未核验',
        'plugin_disabled' => '插件已停用且无待处理订单', 'disabled' => '配置已停用', 'lease_expired' => '采集执行超时，等待恢复',
        'push_plugin' => '此插件通过通知接收到账消息，无需主动采集；接收情况请查看通知接收日志',
    ];

    public function __construct(private readonly RuntimeDiagnosticRepository $records, private readonly RuntimeStatusService $workers,
        private readonly Clock $clock)
    {
    }

    /** @return array<string,mixed> 当前 PHP 入口版本与进程心跳分别展示，HTTP 环境不能代替 CLI 环境验收。 */
    public function overview(): array
    {
        $workers = $this->workers->workers(); // 文件读取在数据库事务外，不持有交易/配置行锁。
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $data = $this->transaction(function () use ($now): array {
            $raw = $this->records->collectionStates(); $states = [];
            foreach (self::STATUSES as $code => $label) { $states[] = ['code' => $code, 'label' => $label, 'count' => $raw[$code] ?? 0]; }
            $unknown = array_sum(array_diff_key($raw, self::STATUSES));
            $backlogs = $this->records->backlogs();
            return ['notifications' => $this->records->notificationSummary($now) + [
                'pending' => $backlogs['notifications'][NotifyConstant::STATUS_PENDING] ?? 0,
                'sending' => $backlogs['notifications'][NotifyConstant::STATUS_SENDING] ?? 0,
                'exhausted' => $backlogs['notifications'][NotifyConstant::STATUS_EXHAUSTED] ?? 0],
                'collection_states' => $states, 'unknown_states' => $unknown];
        });
        $packages = [];
        foreach (['topthink/framework', 'topthink/think-orm', 'topthink/think-validate', 'guzzlehttp/guzzle'] as $name) {
            $packages[$name] = InstalledVersions::isInstalled($name) ? InstalledVersions::getPrettyVersion($name) : null;
        }
        return $data + ['workers' => $workers, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'packages' => $packages,
            'device_status' => 'unknown', 'observed_at' => $now,
            'status_options' => self::STATUSES + ['not_initialized' => '尚无运行记录', 'unknown' => '未知状态，需核对']];
    }

    /**
     * @param array{configuration_id?:string,status?:string} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int} 记录状态不表示平台实时可用。
     */
    public function collections(array $filters, int $page, int $limit): array
    {
        if (isset($filters['status']) && !array_key_exists($filters['status'], self::STATUSES) && !in_array($filters['status'], ['not_initialized', 'unknown'], true)) {
            throw new BusinessException('不支持的采集状态筛选', ApiResponseConstant::VALIDATION_FAILED);
        }
        return $this->transaction(function () use ($filters, $page, $limit): array {
            $rows = $this->records->collectionPage($filters, array_keys(self::STATUSES), $page, $limit);
            $observed = $this->clock->now();
            $now = $observed->format('Y-m-d H:i:s');
            $items = [];
            foreach ($rows['items'] as $row) {
                $exists = $row['runtime_id'] !== null;
                $status = $row['query_key'] === null ? \app\common\constant\ReceiptRuntimeConstant::PUSH : (!$exists ? 'not_initialized' : (isset(self::STATUSES[$row['status']]) ? (int) $row['status'] : 'unknown'));
                $matches = $exists;
                $lease = $row['lease_until'];
                $items[] = ['configuration_id' => (string) $row['id'], 'name' => $row['name'], 'plugin_code' => $row['plugin_code'],
                    'enabled' => (int) $row['enabled'] === 1, 'configuration_version' => (int) $row['config_version'],
                    'status' => $status, 'status_label' => (self::STATUSES[$status] ?? ($exists ? '未知状态，需核对' : '尚无运行记录')),
                    'reason_label' => $row['reason_code'] === null ? null : (self::REASONS[$row['reason_code']] ?? '其他原因，请核对审计及技术日志'),
                    'last_success_at' => $row['last_success_at'], 'last_heartbeat_at' => $row['last_heartbeat_at'],
                    'last_duration_ms' => $row['last_duration_ms'] === null ? null : (int) $row['last_duration_ms'],
                    'next_run_at' => $row['next_run_at'], 'retry_count' => $exists ? (int) $row['retry_count'] : null,
                    'lease_until' => $lease, 'lease_status' => $lease === null ? '暂无执行中的采集任务' : ($lease > $now ? '采集任务执行期限内' : '采集执行超时，等待恢复'),
                    'updated_at' => $row['runtime_updated_at'], 'device_status' => 'unknown'] + $this->coverage($row, $matches, $observed);
            }
            return ['items' => $items, 'total' => $rows['total'], 'page' => $page, 'limit' => $limit];
        });
    }

    /**
     * 只展示持久游标中已完成区间的滞后秒数；平台分页 token、账号身份和范围明细均不返回。
     * 当前配置版本不符、游标损坏或未来时间显示未知，不把它们压成“零延迟”；该信息不证明账号在线。
     *
     * @return array{cursor_status:string,cursor_lag_seconds:?int,cursor_completed_at:?string}
     */
    private function coverage(PaymentPluginConf $row, bool $matches, DateTimeImmutable $now): array
    {
        $empty = ['cursor_status' => 'unavailable', 'cursor_lag_seconds' => null, 'cursor_completed_at' => null];
        if ($row['cursor_json'] === null) { return $empty; }
        $empty['cursor_status'] = 'unknown';
        if (!$matches) { return $empty; }
        try {
            $cursor = is_string($row['cursor_json']) ? json_decode($row['cursor_json'], true, 64, JSON_THROW_ON_ERROR) : $row['cursor_json'];
            if (!is_array($cursor)) { return $empty; }
            $cursor = ReceiptCollectionCursor::read($row['query_key'], $row['plugin_code'], $cursor);
            $through = $cursor['completed_through'];
            if ($through > $now->getTimestamp()) { return $empty; }
            return ['cursor_status' => 'recorded', 'cursor_lag_seconds' => $now->getTimestamp() - $through,
                'cursor_completed_at' => $now->setTimestamp($through)->format('Y-m-d H:i:s')];
        } catch (\JsonException|ReceiptCollectionException) { return $empty; }
    }
}
