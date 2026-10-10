<?php
declare(strict_types=1);

namespace app\repository\system;

use app\model\payment\NotifyTask;
use app\model\payment\ReceiptRuntimeState;
use app\model\payment\PaymentPluginConf;

/** 运行诊断的 ORM 聚合读取，不暴露通知内容、会话或账号凭证，也不执行修复。 */
final class RuntimeDiagnosticRepository
{
    public function __construct(private readonly NotifyTask $notifications, private readonly ReceiptRuntimeState $runtime,
        private readonly PaymentPluginConf $configurations)
    {
    }

    /** @return array{quarantined:int,expired_leases:int} 只是待处理计数，不代表任何支付状态。 */
    public function notificationSummary(string $now): array
    {
        return ['quarantined' => (int) $this->notifications->whereIn('last_response', [
            'quarantined_frozen_payload_invalid', 'quarantined_attempt_ledger_inconsistent', 'quarantined_schedule_invalid',
        ])->count(), 'expired_leases' => (int) $this->notifications->where('status', 1)->where('lease_until', '<=', $now)->count()];
    }

    /** @return array<string,int> 运行态枚举及数量，展示端须将未知值归为异常而非原文输出。 */
    public function collectionStates(): array
    {
        return array_map('intval', $this->runtime->group('status')->column('COUNT(*)', 'status'));
    }

    /** @return array{notifications:array<int,int>} 当前通知责任计数；重试次数不能算作交易次数。 */
    public function backlogs(): array
    {
        return ['notifications' => array_map('intval', $this->notifications->group('status')->column('COUNT(*)', 'status'))];
    }

    /**
     * 配置为主表，尚无运行记录的账号也要出现；账号键与配置 ID 同时匹配，不能串到另一账号的会话。
     *
     * @param array{configuration_id?:string,status?:string} $filters 已校验筛选。
     * @param list<string> $knownStatuses 服务固定状态集合，用于筛选损坏/未知状态，不从请求传入。
     * @return array{items:list<PaymentPluginConf>,total:int} 游标/账号键仅供服务核对覆盖时间，不能直接序列化；不读取会话密文、认证证据或租约 token。
     */
    public function collectionPage(array $filters, array $knownStatuses, int $page, int $limit): array
    {
        $query = $this->configurations->alias('c')->join('ma_payment_channel ch', 'ch.api_config_id=c.id')
            ->whereNull('ch.deleted_at')->leftJoin('ma_receipt_runtime_state r', 'r.query_key=c.query_key');
        if (isset($filters['configuration_id'])) { $query->where('c.id', $filters['configuration_id']); }
        if (isset($filters['status'])) {
            if ($filters['status'] === 'not_initialized') { $query->whereNotNull('c.query_key')->whereNull('r.id'); }
            elseif ((string) $filters['status'] === (string) \app\common\constant\ReceiptRuntimeConstant::PUSH) { $query->whereNull('c.query_key'); }
            elseif ($filters['status'] === 'unknown') { $query->whereNotNull('r.id')->whereNotIn('r.status', $knownStatuses); }
            else { $query->where('r.status', $filters['status']); }
        }
        return ['total' => (clone $query)->count(), 'items' => $query->field([
            'c.id', 'ch.name', 'c.plugin_code', 'ch.enabled', 'c.config_version', 'r.id' => 'runtime_id',
            'r.status', 'r.last_success_at', 'r.last_heartbeat_at', 'r.last_duration_ms', 'r.next_run_at', 'r.retry_count', 'r.reason_code',
            'r.lease_until', 'r.updated_at' => 'runtime_updated_at', 'c.query_key', 'r.cursor_json',
        ])->order('c.id', 'desc')->page($page, $limit)->select()->all()];
    }
}
