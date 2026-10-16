<?php
declare(strict_types=1);

namespace app\service\system\runtime;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\ReceiptRuntimeConstant;
use app\common\interface\ReceiptCollectorInterface;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\repository\system\RuntimeDiagnosticRepository;
use app\service\payment\plugin\PaymentPluginFactoryService;
use Composer\InstalledVersions;
use think\Cache;
use think\Config;

/** 运行维护的白名单读视图，组合进程心跳、采集缓存摘要与通知计数；不触发采集、清理、迁移或平台登录。 */
final class RuntimeQueryService extends BaseService
{
    private const STATUSES = ReceiptRuntimeConstant::LABELS;
    private const REASONS = [
        'configuration_conflict' => '同一登录账号的认证配置不一致，请核对相关通道',
        'authentication_expired' => '平台明确拒绝认证',
        'network_error' => '网络请求未完成',
        'rate_limited' => '平台要求降低请求频率',
        'limit_reached' => '本次查询达到 200 条上限，已处理返回流水且未翻页',
        'scope_mismatch' => '来源与授权收款范围不符',
        'protocol_error' => '来源数据不符合约定',
        'history_gap' => '订单时间超出平台允许查询的范围',
        'budget_exhausted' => '本批采集已达到处理上限，将在下一批继续',
        'configuration_changed' => '配置已更新，等待重新检查',
        'unverified' => '来源尚未核验',
        'plugin_disabled' => '插件已停用且无待处理订单',
        'disabled' => '配置已停用',
        'push_plugin' => '此插件通过通知接收到账消息，无需主动采集；接收情况请查看通知接收日志',
    ];

    public function __construct(
        private readonly RuntimeDiagnosticRepository $records,
        private readonly RuntimeStatusService $workers,
        private readonly Clock $clock,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly PaymentPluginFactoryService $plugins,
    ) {}

    /** @return array<string,mixed> 当前 PHP 入口版本与进程心跳分别展示，HTTP 环境不能代替 CLI 环境验收。 */
    public function overview(): array
    {
        $workers = $this->workers->workers(); // 文件读取在数据库事务外，不持有交易/配置行锁。
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $data = $this->transaction(function () use ($now): array {
            $raw = $this->collectionStates();
            $states = [];
            foreach (self::STATUSES as $code => $label) {
                $states[] = ['code' => $code, 'label' => $label, 'count' => $raw[$code] ?? 0];
            }
            $unknown = array_sum(array_diff_key($raw, self::STATUSES));
            $backlogs = $this->records->backlogs();
            return [
                'notifications' => $this->records->notificationSummary($now) + [
                    'pending' => $backlogs['notifications'][NotifyConstant::STATUS_PENDING] ?? 0,
                    'sending' => $backlogs['notifications'][NotifyConstant::STATUS_SENDING] ?? 0,
                    'exhausted' =>
                        $backlogs['notifications'][NotifyConstant::STATUS_EXHAUSTED] ?? 0,
                ],
                'collection_states' => $states,
                'unknown_states' => $unknown,
            ];
        });
        $packages = [];
        foreach (
            [
                'topthink/framework',
                'topthink/think-orm',
                'topthink/think-validate',
                'guzzlehttp/guzzle',
            ]
            as $name
        ) {
            $packages[$name] = InstalledVersions::isInstalled($name)
                ? InstalledVersions::getPrettyVersion($name)
                : null;
        }
        return $data + [
            'workers' => $workers,
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'packages' => $packages,
            'device_status' => 'unknown',
            'observed_at' => $now,
            'status_options' => self::STATUSES + [
                'not_initialized' => '尚无运行记录',
                'unknown' => '未知状态，需核对',
            ],
        ];
    }

    /**
     * @param array{configuration_id?:string,status?:string} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int} 记录状态不表示平台实时可用。
     */
    public function collections(array $filters, int $page, int $limit): array
    {
        if (
            isset($filters['status']) &&
            !array_key_exists($filters['status'], self::STATUSES) &&
            !in_array($filters['status'], ['not_initialized', 'unknown'], true)
        ) {
            throw new BusinessException(
                '不支持的采集状态筛选',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $items = array_values(
            array_filter(
                $this->collectionItems(),
                static fn(array $item): bool => (!isset($filters['configuration_id']) ||
                    $item['configuration_id'] === $filters['configuration_id']) &&
                    (!isset($filters['status']) || (string) $item['status'] === $filters['status']),
            ),
        );
        return [
            'items' => array_slice($items, ($page - 1) * $limit, $limit),
            'total' => count($items),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** 缓存摘要仅代表上次观测，不由进程心跳推断平台登录。 */
    public function collectionStates(): array
    {
        $counts = [];
        foreach ($this->collectionItems() as $item) {
            $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;
        }
        return $counts;
    }

    private function collectionItems(): array
    {
        $rows = $this->records->collectionRows();
        $pending = $this->records->pendingCollections(
            $this->clock
                ->now()
                ->modify(
                    '-' . (int) $this->config->get('receipt.late_check_seconds', 300) . ' seconds',
                )
                ->format('Y-m-d H:i:s'),
        );
        $store = $this->cache->store('receipt');
        $installed = array_fill_keys($this->plugins->codes(), true);
        $collectors = [];
        $items = [];
        foreach ($rows as $row) {
            $code = $row['plugin_code'];
            if (!array_key_exists($code, $collectors)) {
                $collectors[$code] =
                    isset($installed[$code]) &&
                    $this->plugins->make($code) instanceof ReceiptCollectorInterface;
            }
            $state = $store->get('collection.summary.' . $row['id'], []);
            if (
                !is_array($state) ||
                ($state['config_version'] ?? null) !== (int) $row['config_version']
            ) {
                $state = [];
            }
            $count = $pending[$row['id']] ?? 0;
            $status = $state['status'] ?? ReceiptRuntimeConstant::IDLE;
            if (!isset(self::STATUSES[$status])) {
                $status = 'unknown';
            }
            if (!isset($installed[$code])) {
                $status = 'unknown';
            } elseif (!$collectors[$code]) {
                $status = ReceiptRuntimeConstant::PUSH;
            } elseif (!(int) $row['enabled'] && $count === 0) {
                $status = ReceiptRuntimeConstant::PAUSED;
            }
            $label = self::STATUSES[$status] ?? '未知状态，需核对';
            if (
                $collectors[$code] &&
                $count === 0 &&
                in_array(
                    $status,
                    [ReceiptRuntimeConstant::IDLE, ReceiptRuntimeConstant::HEALTHY],
                    true,
                )
            ) {
                $label = '暂无待核对订单';
            }
            $items[] = [
                'configuration_id' => (string) $row['id'],
                'name' => $row['name'],
                'plugin_code' => $code,
                'enabled' => (bool) $row['enabled'],
                'configuration_version' => (int) $row['config_version'],
                'status' => $status,
                'status_label' => $label,
                'pending_count' => $count,
                'reason_label' => isset($state['reason_code'])
                    ? self::REASONS[$state['reason_code']] ?? '请核对技术日志'
                    : null,
                'last_success_at' => $state['last_success_at'] ?? null,
                'last_verified_at' => $state['last_verified_at'] ?? null,
                'last_duration_ms' => $state['last_duration_ms'] ?? null,
                'next_run_at' => $state['next_run_at'] ?? null,
                'matched_count' => $state['matched_count'] ?? null,
                'retry_count' => $state['retry_count'] ?? 0,
                'updated_at' => $state['updated_at'] ?? null,
                'device_status' => 'unknown',
            ];
        }
        return $items;
    }
}
