<?php
declare(strict_types=1);

namespace app\service\payment\routing;

use app\common\constant\ApiResponseConstant;
use app\common\constant\RouteConstant;
use app\common\util\Clock;
use app\common\util\Money;
use app\exception\BusinessException;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\config\PaymentPollGroupRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\config\PaymentCatalogService;
use app\service\payment\config\PaymentChannelRevisionService;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\receipt\ReceiptScopeService;
use RuntimeException;

/**
 * 应用支付方式绑定的轮询组选路：核对已安装插件、冻结规则和账号归属；本地可选不代表平台在线。
 * 新尝试共用建单外层事务；预览只读当前可用性，不占用通道或替已有支付单换通道。
 */
final class PaymentRouteService
{
    public function __construct(
        private readonly PaymentCatalogRepository $catalog,
        private readonly PaymentPollGroupRepository $groups,
        private readonly PaymentChannelRepository $channels,
        private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentChannelRevisionService $revisions,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly ReceiptScopeService $scopeAccess,
        private readonly PaymentCatalogService $management,
        private readonly PayOrderRepository $orders,
        private readonly Clock $clock,
    ) {}

    /**
     * 只在尝试尚不存在时选路；先筛有效组成员，再按组策略选择，不在范围锁后切换通道。
     * 新尝试的外层持应用/业务单锁，本层依次锁支付方式、绑定、组和配置/通道；预览不加锁。
     *
     * @param bool $advance 新尝试加锁并推进游标；false 为只读预览，实际下单必须重新核验。
     * @return array{channel:PaymentChannel,pay_type_id:string,config_version:int,plugin_version:string,poll_group_id:string,route_mode:int}
     */
    public function selectFor(
        string $appId,
        int $amount,
        string $payType,
        bool $advance = true,
    ): array {
        $type = $advance ? $this->catalog->lockType($payType) : $this->catalog->findCode($payType);
        if ($type === null || (int) $type['enabled'] !== 1) {
            throw new BusinessException('支付方式不可用', ApiResponseConstant::SERVICE_UNAVAILABLE);
        }
        $binding = $this->groups->binding($appId, $type['id'], $advance);
        if ($binding === null || (int) $binding['status'] !== 1) {
            throw new BusinessException(
                '应用尚未启用该支付方式的轮询组',
                ApiResponseConstant::SERVICE_UNAVAILABLE,
            );
        }
        $group = $advance
            ? $this->groups->lock($binding['poll_group_id'])
            : $this->groups->find($binding['poll_group_id']) ??
                throw new RuntimeException('轮询组不存在');
        if ((int) $group['status'] !== 1 || $group['pay_type_id'] !== $type['id']) {
            throw new BusinessException(
                '轮询组已停用或方式不一致',
                ApiResponseConstant::SERVICE_UNAVAILABLE,
            );
        }
        $members = [];
        foreach ($this->groups->members($group['id'], $advance) as $member) {
            if ((int) $member['status'] !== 1) {
                continue;
            }
            $members[$member['channel_id']] = $member;
        }
        $candidates = $this->channels->forIds(array_map('strval', array_keys($members)));
        // 先按配置/通道的稳定顺序加锁，再按业务顺序选择，避免共享通道的不同组反向取锁。
        usort(
            $candidates,
            static fn(PaymentChannel $a, PaymentChannel $b): int => (int) $a['api_config_id'] <=>
            (int) $b['api_config_id'] ?:
            (int) $a['id'] <=> (int) $b['id'],
        );
        $eligible = [];
        // 本次复用同编码的准入结果和定义；新尝试仍先锁插件行，不跨事务缓存。
        $definitions = [];
        foreach ($candidates as $candidate) {
            $configuration = $advance
                ? $this->configurations->lock($candidate['api_config_id'])
                : $this->configurations->find($candidate['api_config_id']) ??
                    throw new RuntimeException('收款配置不存在');
            $channel = $advance ? $this->channels->lock($candidate['id']) : $candidate;
            if (
                $channel['api_config_id'] !== $configuration['id'] ||
                (int) $configuration['enabled'] !== 1 ||
                $channel['plugin_code'] !== $configuration['plugin_code'] ||
                (int) $channel['enabled'] !== 1 ||
                $channel['current_revision_id'] === null ||
                !in_array($payType, $channel['pay_types'], true) ||
                (int) $channel['min_amount'] > $amount ||
                (int) $channel['max_amount'] < $amount
            ) {
                continue;
            }
            $configurationRevision =
                $this->configurations->revision(
                    $configuration['id'],
                    (int) $configuration['config_version'],
                    $advance,
                ) ?? throw new RuntimeException('当前收款配置版本缺失');
            $code = $channel['plugin_code'];
            if (!array_key_exists($code, $definitions)) {
                $definitions[$code] = $this->management->allowsNew($code, $advance)
                    ? $this->plugins->definition($code)
                    : null;
            }
            $definition = $definitions[$code];
            if ($definition === null || !in_array($payType, $definition->payTypes, true)) {
                continue;
            }
            $authorized = $this->scopeAccess->current($configuration, false);
            if (!isset($authorized[$channel['receipt_scope_key']])) {
                continue;
            }
            $snapshot = $this->revisions->snapshot(
                $channel['id'],
                $channel['current_revision_id'],
                $advance,
            );
            if (
                $snapshot['config_version'] !== (int) $configuration['config_version'] ||
                !$this->revisions->rulesMatch($channel, $snapshot['channel']) ||
                $configurationRevision['config_json'] !== $configuration['config_json']
            ) {
                continue;
            }
            $this->assertRules($channel);
            $eligible[] = [
                'member' => $members[$channel['id']],
                'poll_group_id' => $group['id'],
                'route_mode' => $group['route_mode'],
                'channel' => $channel,
                'pay_type_id' => $type['id'],
                'config_version' => (int) $configuration['config_version'],
                'plugin_version' => $definition->version,
            ];
        }
        // 新尝试等待全部候选锁后再读时间；预览复用同一额度规则但不预留额度。
        $evaluatedAt = $this->clock->now();
        $limitedIds = [];
        foreach ($eligible as $index => $row) {
            $channel = $row['channel'];
            if (!$this->withinHours($channel, $evaluatedAt->format('H:i'))) {
                unset($eligible[$index]);
                continue;
            }
            if (
                (int) $channel['daily_limit_amount'] > 0 ||
                (int) $channel['daily_limit_count'] > 0
            ) {
                $limitedIds[] = $channel['id'];
            }
        }
        $day = $evaluatedAt->setTime(0, 0);
        $usages = $this->orders->channelDailyUsages(
            $limitedIds,
            $day->format('Y-m-d H:i:s'),
            $day->modify('+1 day')->format('Y-m-d H:i:s'),
            $evaluatedAt->format('Y-m-d H:i:s'),
        );
        foreach ($eligible as $index => $row) {
            $channel = $row['channel'];
            $usage = $usages[$channel['id']] ?? ['amount' => 0, 'count' => 0];
            if (
                ((int) $channel['daily_limit_amount'] > 0 &&
                    $usage['amount'] + $amount > (int) $channel['daily_limit_amount']) ||
                ((int) $channel['daily_limit_count'] > 0 &&
                    $usage['count'] >= (int) $channel['daily_limit_count'])
            ) {
                unset($eligible[$index]);
            }
        }
        if ($eligible === []) {
            throw new BusinessException(
                '轮询组暂无可用收款通道',
                ApiResponseConstant::SERVICE_UNAVAILABLE,
            );
        }
        usort(
            $eligible,
            static fn(array $a, array $b): int => (int) $a['member']['sort_no'] <=>
            (int) $b['member']['sort_no'] ?:
            (int) $a['channel']['id'] <=> (int) $b['channel']['id'],
        );
        $selected = 0;
        switch ($group['route_mode']) {
            case RouteConstant::ORDER:
                $selected = (int) $binding['cursor'] % count($eligible);
                // 游标只在新尝试的事务中修改；预览读取同一个下次位置，不消耗序号。
                if ($advance) {
                    $this->groups->advance(
                        $binding,
                        ($selected + 1) % count($eligible),
                        $this->clock->now()->format('Y-m-d H:i:s'),
                    );
                }
                break;
            case RouteConstant::WEIGHTED:
                $total = array_sum(
                    array_map(
                        static fn(array $row): int => (int) $row['member']['weight'],
                        $eligible,
                    ),
                );
                if ($total < 1) {
                    throw new RuntimeException('轮询组权重无效');
                }
                $draw = random_int(1, $total);
                foreach ($eligible as $index => $row) {
                    $draw -= (int) $row['member']['weight'];
                    if ($draw <= 0) {
                        $selected = $index;
                        break;
                    }
                }
                break;
            case RouteConstant::FIRST_AVAILABLE:
                foreach ($eligible as $index => $row) {
                    if ((int) $row['member']['is_default'] === 1) {
                        $selected = $index;
                        break;
                    }
                }
                break;
            default:
                throw new RuntimeException('未知轮询组策略');
        }
        unset($eligible[$selected]['member']);
        return $eligible[$selected];
    }

    /** 开始时刻包含、结束时刻不包含；例如 22:00～06:00 跨午夜，空值表示全天。 */
    private function withinHours(PaymentChannel $channel, string $time): bool
    {
        $from = $channel['available_from'];
        $to = $channel['available_to'];
        if ($from === null && $to === null) {
            return true;
        }
        if (!is_string($from) || !is_string($to) || $from === $to) {
            throw new RuntimeException('通道可用时段无效');
        }
        return $from < $to ? $time >= $from && $time < $to : $time >= $from || $time < $to;
    }

    /** 通用选路检查订单金额和路由策略；付款材料、有效期和匹配参数仍由插件处理。 */
    private function assertRules(PaymentChannel $channel): void
    {
        Money::assertRange((int) $channel['max_amount']);
        if (
            (int) $channel['min_amount'] < 1 ||
            (int) $channel['min_amount'] > (int) $channel['max_amount']
        ) {
            throw new RuntimeException('通道付款规则越界');
        }
    }
}
