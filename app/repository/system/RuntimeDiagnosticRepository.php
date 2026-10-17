<?php
declare(strict_types=1);

namespace app\repository\system;

use app\common\constant\TradeConstant;
use app\model\payment\NotifyTask;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PayOrder;

/** 运行诊断只读聚合；采集状态由服务读缓存，本仓储不访问平台或读取凭证正文。 */
final class RuntimeDiagnosticRepository
{
    public function __construct(
        private readonly NotifyTask $notifications,
        private readonly PayOrder $orders,
        private readonly PaymentPluginConf $configurations,
    ) {}

    public function notificationSummary(string $now): array
    {
        return [
            'quarantined' => (int) $this->notifications
                ->whereIn('last_response', [
                    'quarantined_frozen_payload_invalid',
                    'quarantined_attempt_state_invalid',
                    'quarantined_schedule_invalid',
                ])
                ->count(),
            'expired_leases' => (int) $this->notifications
                ->where('status', 1)
                ->where('lease_until', '<=', $now)
                ->count(),
        ];
    }

    public function backlogs(): array
    {
        return [
            'notifications' => array_map(
                'intval',
                $this->notifications->group('status')->column('COUNT(*)', 'status'),
            ),
        ];
    }

    /** @return list<PaymentPluginConf> 显式字段白名单，绝不把 config_json 交给诊断页面。 */
    public function collectionRows(array $configurationIds = []): array
    {
        return $this->configurations
            ->alias('c')
            ->join('ma_payment_channel ch', 'ch.api_config_id=c.id')
            ->whereNull('ch.deleted_at')
            ->when(
                $configurationIds !== [],
                static fn($query) => $query->whereIn('c.id', $configurationIds),
            )
            ->field(['c.id', 'ch.name', 'c.plugin_code', 'ch.enabled', 'c.config_version'])
            ->order('c.id', 'desc')
            ->select()
            ->all();
    }

    /** @return array<string,int> 按原通道版本统计待核对订单，不从活动配置推断历史归属。 */
    public function pendingCollections(string $since, array $configurationIds = []): array
    {
        return array_map(
            'intval',
            $this->orders
                ->alias('p')
                ->join('ma_payment_channel_revision r', 'r.id=p.channel_revision_id')
                ->whereIn('p.status', [
                    TradeConstant::ORDER_STATUS_CREATED,
                    TradeConstant::ORDER_STATUS_PAYING,
                    TradeConstant::ORDER_STATUS_CLOSED,
                    TradeConstant::ORDER_STATUS_TIMEOUT,
                ])
                ->whereNotNull('p.request_at')
                ->where('p.expire_at', '>=', $since)
                ->when(
                    $configurationIds !== [],
                    static fn($query) => $query->whereIn('r.api_config_id', $configurationIds),
                )
                ->group('r.api_config_id')
                ->column('COUNT(*)', 'r.api_config_id'),
        );
    }
}
