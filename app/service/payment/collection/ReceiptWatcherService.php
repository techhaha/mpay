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
use RuntimeException;
use think\Cache;
use think\Config;
use think\Validate;
use UnexpectedValueException;

/**
 * 内置监听工具：按订单装配插件输入，并提交已完成的单次查询批次。
 * 平台账号合并、登录及保活由插件负责；网络不在数据库事务中，支付确认只调用统一生命周期。
 */
final class ReceiptWatcherService
{
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
     * 一个角色锁下执行一轮；已分类平台故障不终止其他账号，程序/数据库错误向命令抛出。
     * @param callable|null $heartbeat 批次之间刷新本地进程心跳，不代表平台在线。
     * @return array{queried:int,verified:int,matched:int,failed:int}
     */
    public function runRound(int $limit = 100, ?callable $heartbeat = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new UnexpectedValueException('监听批次上限无效');
        }
        $counts = ['queried' => 0, 'verified' => 0, 'matched' => 0, 'failed' => 0];
        $codes = $this->plugins->codes();
        $store = $this->cache->store('receipt');
        $last = $store->get('watch.plugin_rotation');
        $offset = is_string($last) ? array_search($last, $codes, true) : false;
        if ($offset !== false) {
            $codes = [...array_slice($codes, $offset + 1), ...array_slice($codes, 0, $offset + 1)];
        }
        $upper = $this->orders->collectionUpperId();
        foreach ($codes as $code) {
            if ($counts['queried'] + $counts['verified'] + $counts['failed'] >= $limit) {
                break;
            }
            $plugin = $this->plugins->make($code);
            if (!($plugin instanceof ReceiptCollectorInterface)) {
                continue;
            }
            $prelogin = $this->plugins->preloginEnabled($plugin);
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $store->set('watch.plugin_rotation', $code, 0);
            try {
                // 保活需要没有待付订单的启用通道；未声明时只读取原单依赖的版本。
                [$orders, $channels] = $this->input($code, $upper, $prelogin);
            } catch (ReceiptCollectionException $error) {
                // 不能拿截断候选匹配，但单个插件超容量也不能阻断其他插件。
                $this->logger->record(
                    'receipt.collection',
                    ['plugin_code' => $code, 'reason_code' => $error->reasonCode],
                    'warning',
                );
                $counts['failed']++;
                continue;
            }
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
                        static fn(array $pay): bool => isset($covered[$pay['channel_revision_id']]),
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
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                if ($counts['queried'] + $counts['verified'] + $counts['failed'] >= $limit) {
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
                    $this->activity($code, $covered, $batch['error'], true, 0, false, $started);
                    if ($heartbeat !== null) {
                        $heartbeat();
                    }
                    if ($counts['queried'] + $counts['verified'] + $counts['failed'] >= $limit) {
                        break;
                    }
                    $started = hrtime(true);
                }
            }
        }
        return $counts;
    }

    /**
     * 数据库分页只为控制读取体积；全部候选装配完才调用插件，避免同账号按页重复查询。
     * @return array{0:array<string,array>,1:array<string,array>}
     */
    private function input(string $code, string $upper, bool $prelogin): array
    {
        $since = $this->clock
            ->now()
            ->modify('-' . (int) $this->config->get('receipt.late_check_seconds', 300) . ' seconds')
            ->format('Y-m-d H:i:s');
        $size = (int) $this->config->get('receipt.order_page_size', 500);
        $maximum = (int) $this->config->get('receipt.max_orders_per_plugin', 10000);
        $orders = [];
        $versions = [];
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
                $revision = $pay->channelRevision;
                if (!$revision || $revision['channel_id'] !== $pay['channel_id']) {
                    throw new RuntimeException('订单通道快照缺失');
                }
                $versions[$revision['id']] = $revision;
                $orders[$pay['pay_no']] = [
                    'id' => $pay['id'],
                    'pay_no' => $pay['pay_no'],
                    'channel_id' => $pay['channel_id'],
                    'channel_revision_id' => $revision['id'],
                    'pay_type' => $pay['ext_json']['payment_context']['pay_type'],
                    'pay_amount' => (int) $pay['pay_amount'],
                    'ext_json' => $pay['ext_json'] ?? [],
                    'request_at' => $pay['request_at'],
                    'expire_at' => $pay['expire_at'],
                    'created_at' => $pay['created_at'],
                ];
            }
        } while (count($rows) === $size);
        if ($prelogin) {
            $enabled = $this->channels->preloginChannels($code);
            $ids = array_map(
                static fn($channel): string => $channel['current_revision_id'],
                $enabled,
            );
            $versions += $this->channels->revisionsForIds($ids);
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
                'current_config_version' =>
                    $configuration === null ? null : $configuration['config_version'],
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
        ];
        if (
            array_diff(array_keys($error), array_keys($rules)) ||
            !(new Validate())->rule($rules)->failException(false)->check($error)
        ) {
            throw new UnexpectedValueException('采集错误分类或重试间隔无效');
        }
    }

    /** 只缓存运行摘要，不保存流水和凭证；后台分别显示查单与登录验证时间。 */
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
            $state = $store->get($key, []);
            if (
                !is_array($state) ||
                ($state['config_version'] ?? null) !== $channel['config_version']
            ) {
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
            $state['retry_count'] = $error === null ? 0 : (int) ($state['retry_count'] ?? 0) + 1;
            $state['next_run_at'] = $now
                ->modify(
                    '+' .
                        ($error['retry_after'] ??
                            (int) $this->config->get(
                                $probe
                                    ? 'receipt.prelogin_interval_seconds'
                                    : 'receipt.query_interval_seconds',
                                $probe ? 600 : 3,
                            )) .
                        ' seconds',
                )
                ->format('Y-m-d H:i:s');
            if ($error === null) {
                $state['last_verified_at'] = $state['updated_at'];
                if (!$probe) {
                    $state['last_success_at'] = $state['updated_at'];
                    $state['matched_count'] = $matched;
                }
            }
            $store->set($key, $state, 0);
            $this->logger->record(
                $probe ? 'receipt.prelogin' : 'receipt.collection',
                [
                    'plugin_code' => $code,
                    'api_config_id' => $channel['api_config_id'],
                    'config_version' => $channel['config_version'],
                    'channel_id' => $channel['channel_id'],
                    'duration_ms' => $state['last_duration_ms'],
                    'reason_code' => $error['reason'] ?? ($limited ? 'limit_reached' : 'completed'),
                ],
                $error === null && !$limited ? 'info' : 'warning',
            );
        }
    }
}
