<?php
declare(strict_types=1);

namespace app\service\payment\collection;

use app\common\constant\ReceiptRuntimeConstant;
use app\common\interface\ReceiptCollectorInterface;
use app\common\util\Clock;
use app\common\util\SafeLogger;
use app\exception\ReceiptCollectionException;
use app\model\payment\PaymentChannelRevision;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\receipt\PayOrderCallbackService;
use PDOException;
use RuntimeException;
use think\Cache;
use think\Config;
use think\db\exception\DbException;
use think\Validate;
use Throwable;
use UnexpectedValueException;

/**
 * 内置监听工具：按订单装配插件输入，并提交已完成的单次查询批次。
 * 平台账号合并、登录及保活由插件负责；网络不在数据库事务中，支付确认只调用统一生命周期。
 */
final class ReceiptWatcherService
{
    private const SUMMARY_WRITE_INTERVAL_NANOSECONDS = 10_000_000_000;

    /** @var array<string,array{state:array<string,mixed>,due_at:int}> due_at 为单调时钟纳秒；仅缓冲展示摘要。 */
    private array $pendingActivity = [];
    /** @var array<string,int> 最近 10 秒内成功写入的单调时间，过期项随循环清理，不长期积累通道。 */
    private array $activityWrittenAt = [];

    /** @var array<string,int> 未能形成正常账号批次的插件退避；不接管插件已有的账号重试状态。 */
    private array $retryAt = [];

    public function __construct(
        private readonly PayOrderRepository $orders,
        private readonly PaymentChannelRepository $channels,
        private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly PayOrderCallbackService $callbacks,
        private readonly Clock $clock,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly SafeLogger $logger,
    ) {}

    /**
     * 一个角色锁下执行一轮；账号故障按插件批次返回，额外的插件任务故障退避后继续其他插件。数据库和入口故障仍上抛。
     * @param callable|null $heartbeat 批次之间刷新本地进程心跳，不代表平台在线。
     * @return array{queried:int,verified:int,matched:int,failed:int}
     */
    public function runRound(int $limit = 100, ?callable $heartbeat = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new UnexpectedValueException('监听批次上限无效');
        }
        $counts = ['queried' => 0, 'verified' => 0, 'matched' => 0, 'failed' => 0];
        $upper = $this->orders->collectionUpperId();
        $since = $this->clock
            ->now()
            ->modify('-' . (int) $this->config->get('receipt.late_check_seconds', 300) . ' seconds')
            ->format('Y-m-d H:i:s');
        // 只处理原单和启用通道涉及的插件；接口判断和执行实例创建留在逐插件异常隔离内。
        $codes = array_values(
            array_unique([
                ...$this->orders->collectionPlugins($since, $upper),
                ...$this->channels->preloginPlugins(),
            ]),
        );
        sort($codes, SORT_STRING);
        $store = $this->cache->store('receipt');
        $last = $store->get('watch.plugin_rotation');
        $offset = is_string($last) ? array_search($last, $codes, true) : false;
        if ($offset !== false) {
            $codes = [...array_slice($codes, $offset + 1), ...array_slice($codes, 0, $offset + 1)];
        }
        $next = $last;
        try {
            foreach ($codes as $code) {
                if ($counts['queried'] + $counts['verified'] + $counts['failed'] >= $limit) {
                    break;
                }
                if (($this->retryAt[$code] ?? 0) > hrtime(true)) {
                    continue;
                }
                unset($this->retryAt[$code]);
                // 心跳是共享运行设施，不能将其写入故障归为某个插件的问题。
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                try {
                    $definition = $this->plugins->definition($code);
                    if (!is_a($definition->className, ReceiptCollectorInterface::class, true)) {
                        continue;
                    }
                    /** @var ReceiptCollectorInterface $plugin */
                    $plugin = $this->plugins->make($code);
                    $prelogin = $plugin->isPreloginEnabled();
                    $next = $code;
                    // 保活需要没有待付订单的启用通道；未声明时只读取原单依赖的版本。
                    [$orders, $channels] = $this->input($code, $since, $upper, $prelogin);
                    $deadline = $this->clock
                        ->now()
                        ->modify(
                            '+' .
                                (int) $this->config->get('receipt.plugin_budget_seconds', 30) .
                                ' seconds',
                        );
                    $started = hrtime(true);
                    foreach ($plugin->collect($orders, $channels, $deadline) as $batch) {
                        $covered = $this->batchChannels($batch, $channels);
                        $this->validateError($batch['error'] ?? null);
                        if (
                            !isset($batch['records'], $batch['limit_reached']) ||
                            !is_array($batch['records']) ||
                            !array_is_list($batch['records']) ||
                            count($batch['records']) > 200 ||
                            !is_bool($batch['limit_reached']) ||
                            ($batch['error'] !== null && $batch['records'] !== [])
                        ) {
                            throw new UnexpectedValueException('采集批次结构无效');
                        }
                        $matched = 0;
                        if ($batch['error'] === null) {
                            $selected = array_filter(
                                $orders,
                                static fn(array $pay): bool => isset(
                                    $covered[$pay['channel_revision_id']],
                                ),
                            );
                            if ($selected === []) {
                                throw new UnexpectedValueException('采集批次没有原单来源');
                            }
                            $matched = $this->callbacks->receiveCollected(
                                $plugin,
                                $covered,
                                $selected,
                                $batch['records'],
                                bin2hex(random_bytes(16)),
                            );
                            $counts['queried']++;
                            $counts['matched'] += $matched;
                        } else {
                            $counts['failed']++;
                        }
                        $this->activity(
                            $code,
                            $covered,
                            $batch['error'],
                            false,
                            $matched,
                            $batch['limit_reached'],
                            $started,
                        );
                        if (
                            $counts['queried'] + $counts['verified'] + $counts['failed'] >=
                            $limit
                        ) {
                            break;
                        }
                        $started = hrtime(true);
                    }
                    if (
                        $prelogin &&
                        $this->clock->now() < $deadline &&
                        $counts['queried'] + $counts['verified'] + $counts['failed'] < $limit
                    ) {
                        foreach ($plugin->prelogin($channels, $deadline) as $batch) {
                            $covered = $this->batchChannels($batch, $channels);
                            $this->validateError($batch['error'] ?? null);
                            $counts[$batch['error'] === null ? 'verified' : 'failed']++;
                            $this->activity(
                                $code,
                                $covered,
                                $batch['error'],
                                true,
                                0,
                                false,
                                $started,
                            );
                            if (
                                $counts['queried'] + $counts['verified'] + $counts['failed'] >=
                                $limit
                            ) {
                                break;
                            }
                            $started = hrtime(true);
                        }
                    }
                } catch (DbException | PDOException $error) {
                    throw $error;
                } catch (Throwable $error) {
                    // 不重跑已经提交的确认、不伪造空批次成功；未处理账号留待原查询窗口重试。
                    $wait =
                        $error instanceof ReceiptCollectionException
                            ? $error->retryAfterSeconds ?? 60
                            : 60;
                    $this->retryAt[$code] = hrtime(true) + $wait * 1000000000;
                    // 只有插件返回的批次才更新通道摘要；任务异常不推断平台原因或受影响账号。
                    $this->logger->record(
                        'receipt.task_failed',
                        [
                            'plugin_code' => $code,
                            'reason_code' =>
                                $error instanceof ReceiptCollectionException
                                    ? $error->reasonCode
                                    : 'task_fault',
                        ],
                        'error',
                    );
                    $this->logger->exception($error);
                    $counts['failed']++;
                }
                if ($heartbeat !== null) {
                    $heartbeat();
                }
            }
        } finally {
            // 一轮只保存最终轮转位置；无变化时不覆盖文件。
            if ($next !== $last) {
                $store->set('watch.plugin_rotation', $next, 0);
            }
        }
        return $counts;
    }

    /**
     * 数据库分页只为控制读取体积；全部候选装配完才调用插件，避免同账号按页重复查询。
     * @return array{0:array<string,array>,1:array<string,array>}
     */
    private function input(string $code, string $since, string $upper, bool $prelogin): array
    {
        $size = (int) $this->config->get('receipt.order_page_size', 500);
        $maximum = (int) $this->config->get('receipt.max_orders_per_plugin', 10000);
        $orders = [];
        $after = '0';
        $scanned = 0;
        do {
            $rows = $this->orders->collectionPage($code, $since, $after, $upper, $size);
            foreach ($rows as $pay) {
                $after = $pay['id'];
                if (++$scanned > $maximum) {
                    throw new ReceiptCollectionException('capacity_exceeded');
                }
                // 原单扩展原样交插件解释；采集器不推断任何插件的准备字段或匹配方式。
                $orders[$pay['pay_no']] = [
                    'id' => $pay['id'],
                    'pay_no' => $pay['pay_no'],
                    'channel_id' => $pay['channel_id'],
                    'channel_revision_id' => $pay['channel_revision_id'],
                    'pay_type' => $pay['ext_json']['payment_context']['pay_type'],
                    'pay_amount' => (int) $pay['pay_amount'],
                    'ext_json' => $pay['ext_json'] ?? [],
                    'request_at' => $pay['request_at'],
                    'expire_at' => $pay['expire_at'],
                    'created_at' => $pay['created_at'],
                ];
            }
        } while (count($rows) === $size);
        // 订单与保活共用本轮不可变快照；不在每页重复预载，也不跨轮缓存。
        $enabled = $prelogin ? $this->channels->preloginChannels($code) : [];
        $ids = array_column($orders, 'channel_revision_id');
        foreach ($enabled as $channel) {
            $ids[] = $channel['current_revision_id'];
        }
        $versions = $this->channels->revisionsForIds($ids);
        foreach ($orders as $pay) {
            $revision = $versions[$pay['channel_revision_id']] ?? null;
            if ($revision === null || $revision['channel_id'] !== $pay['channel_id']) {
                throw new RuntimeException('订单通道快照缺失或归属不一致');
            }
        }
        foreach ($enabled as $channel) {
            $revision = $versions[$channel['current_revision_id']] ?? null;
            if ($revision === null || $revision['channel_id'] !== $channel['id']) {
                throw new RuntimeException('保活通道快照缺失或归属不一致');
            }
        }
        $ids = array_map(
            static fn(PaymentChannelRevision $version): string => $version['api_config_id'],
            $versions,
        );
        $current = $this->configurations->forIds($ids);
        $channels = [];
        foreach ($versions as $id => $revision) {
            $snapshot = $revision['snapshot_json'];
            if (!is_array($snapshot) || ($snapshot['channel']['plugin_code'] ?? null) !== $code) {
                throw new RuntimeException('插件通道快照归属错误');
            }
            $configuration = $current[$revision['api_config_id']] ?? null;
            $channels[$id] = [
                'channel_id' => $revision['channel_id'],
                'api_config_id' => $revision['api_config_id'],
                'config_version' => $revision['config_version'],
                'plugin_code' => $code,
                'receipt_scope_key' => $snapshot['channel']['receipt_scope_key'],
                'configuration' => $snapshot['configuration'],
                'current_configuration' =>
                    $configuration === null ? null : $configuration['config_json'],
            ];
        }
        return [$orders, $channels];
    }

    /** 插件只能报告本次真实传入的通道版本，不能扩大确认来源。 */
    private function batchChannels(array $batch, array $channels): array
    {
        $ids = $batch['channel_revision_ids'] ?? null;
        if (
            !is_array($ids) ||
            !array_is_list($ids) ||
            $ids === [] ||
            count(array_unique($ids)) !== count($ids) ||
            !array_key_exists('error', $batch)
        ) {
            throw new UnexpectedValueException('采集批次来源无效');
        }
        $result = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($channels[$id])) {
                throw new UnexpectedValueException('采集引用批次外通道');
            }
            $result[$id] = $channels[$id];
        }
        return $result;
    }

    private function validateError(?array $error): void
    {
        if ($error === null) {
            return;
        }
        $rules = [
            'reason' => ['require', 'string', 'in' => ReceiptCollectionException::REASONS],
            'retry_after' => [
                'require',
                static fn(mixed $value): bool => is_int($value),
                'between' => [1, 86400],
            ],
            'source' => ['string', 'in' => ['platform', 'plugin', 'system']],
            'stage' => [
                static fn(mixed $value): bool => is_string($value) &&
                    mb_check_encoding($value, 'UTF-8') &&
                    strlen($value) <= 64,
            ],
            'platform_code' => [
                static fn(mixed $value): bool => $value === null ||
                    (is_string($value) &&
                        mb_check_encoding($value, 'UTF-8') &&
                        strlen($value) <= 128),
            ],
            'message' => [
                static fn(mixed $value): bool => is_string($value) &&
                    mb_check_encoding($value, 'UTF-8') &&
                    strlen($value) <= 2048,
            ],
            'http_status' => [
                static fn(mixed $value): bool => $value === null ||
                    (is_int($value) && $value >= 100 && $value <= 599),
            ],
            'message_truncated' => [static fn(mixed $value): bool => is_bool($value)],
        ];
        if (
            array_diff(array_keys($error), array_keys($rules)) ||
            !(new Validate())->rule($rules)->failException(false)->check($error)
        ) {
            throw new UnexpectedValueException('采集错误分类、重试间隔或诊断字段无效');
        }
    }

    /**
     * 循环心跳及等待期间保存到期摘要；正常退出强制保存剩余摘要。
     * 网络调用期间不会中断请求刷新展示，业务确认与账号重试状态不经过此缓冲。
     */
    public function flushActivity(bool $force = false): void
    {
        $now = hrtime(true);
        foreach ($this->activityWrittenAt as $key => $writtenAt) {
            if ($now - $writtenAt >= self::SUMMARY_WRITE_INTERVAL_NANOSECONDS) {
                unset($this->activityWrittenAt[$key]);
            }
        }
        if ($this->pendingActivity === []) {
            return;
        }
        $store = $this->cache->store('receipt');
        foreach ($this->pendingActivity as $key => $pending) {
            if (($force || $pending['due_at'] <= $now) && $store->set($key, $pending['state'], 0)) {
                $this->activityWrittenAt[$key] = $now;
                unset($this->pendingActivity[$key]);
            }
        }
    }

    /** 普通展示摘要按 10 秒合并；首次、版本/状态变化、失败和确认到账立即写，日志保留原策略。 */
    private function activity(
        string $code,
        array $channels,
        ?array $error,
        bool $probe,
        int $matched,
        bool $limited,
        int $started,
    ): void {
        $store = $this->cache->store('receipt');
        $now = $this->clock->now();
        foreach ($channels as $channel) {
            $key = 'collection.summary.' . $channel['api_config_id'];
            $previous = $this->pendingActivity[$key]['state'] ?? $store->get($key, []);
            $previous = is_array($previous) ? $previous : [];
            $state = $previous;
            $versionChanged = ($state['config_version'] ?? null) !== $channel['config_version'];
            if ($versionChanged) {
                $state = [];
            }
            $state['config_version'] = $channel['config_version'];
            $state['status'] =
                $error === null
                    ? ReceiptRuntimeConstant::HEALTHY
                    : ReceiptRuntimeConstant::failure($error['reason']);
            $state['reason_code'] = $error['reason'] ?? ($limited ? 'limit_reached' : null);
            $state['last_duration_ms'] = (int) ((hrtime(true) - $started) / 1000000);
            $state['updated_at'] = $now->format('Y-m-d H:i:s');
            if ($error !== null) {
                // 仅保存最近一次失败；恢复后保留供排查，不重写成成功或清掉历史错误。
                $state['last_error'] = array_diff_key($error, ['retry_after' => true]) + [
                    'occurred_at' => $state['updated_at'],
                ];
                $state['recovered_at'] = null;
            } elseif (isset($state['last_error']) && empty($state['recovered_at'])) {
                $state['recovered_at'] = $state['updated_at'];
            }
            $state['consecutive_failures'] =
                $error === null ? 0 : (int) ($state['consecutive_failures'] ?? 0) + 1;
            if ($error === null) {
                $state['last_verified_at'] = $state['updated_at'];
                if (!$probe) {
                    $state['last_success_at'] = $state['updated_at'];
                    $state['matched_count'] = $matched;
                }
            }
            $important =
                $error !== null ||
                $matched > 0 ||
                $versionChanged ||
                ($previous['status'] ?? null) !== $state['status'] ||
                ($previous['reason_code'] ?? null) !== $state['reason_code'] ||
                (!$probe && !isset($previous['last_success_at'])) ||
                ($probe && !isset($previous['last_verified_at']));
            $tick = hrtime(true);
            $writtenAt = $this->activityWrittenAt[$key] ?? null;
            $dueAt = ($writtenAt ?? 0) + self::SUMMARY_WRITE_INTERVAL_NANOSECONDS;
            if ($important || $writtenAt === null || $dueAt <= $tick) {
                if ($store->set($key, $state, 0)) {
                    $this->activityWrittenAt[$key] = $tick;
                    unset($this->pendingActivity[$key]);
                } else {
                    // 未写成功不消耗节流时间，下一次循环继续尝试保存展示状态。
                    $this->pendingActivity[$key] = ['state' => $state, 'due_at' => $tick];
                }
            } else {
                $this->pendingActivity[$key] = ['state' => $state, 'due_at' => $dueAt];
            }
            $this->logger->record(
                $probe ? 'receipt.prelogin' : 'receipt.collection',
                [
                    'plugin_code' => $code,
                    'api_config_id' => $channel['api_config_id'],
                    'config_version' => $channel['config_version'],
                    'channel_id' => $channel['channel_id'],
                    'duration_ms' => $state['last_duration_ms'],
                    'reason_code' => $error['reason'] ?? ($limited ? 'limit_reached' : 'completed'),
                    'error_source' => $error['source'] ?? null,
                    'error_stage' => $error['stage'] ?? null,
                    'platform_code' => $error['platform_code'] ?? null,
                    'error_message' => $error['message'] ?? null,
                    'message_truncated' => $error['message_truncated'] ?? false,
                    'http_status' => $error['http_status'] ?? null,
                ],
                $error === null && !$limited ? 'info' : 'warning',
            );
        }
    }
}
