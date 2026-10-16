<?php
declare(strict_types=1);

namespace app\repository\ops;

use app\common\constant\TradeConstant;
use app\model\payment\BizOrder;
use app\model\payment\NotifyTask;
use app\model\payment\PayOrder;
use app\model\payment\ReceiptFlow;
use RuntimeException;
use think\db\Query;

/** 工作台聚合读取；按业务、支付、流水和通知各自身份计数，不联表重复累加金额，不负责统计解释。 */
final class PaymentStatisticsRepository
{
    public function __construct(
        private readonly BizOrder $orders,
        private readonly PayOrder $payments,
        private readonly ReceiptFlow $flows,
        private readonly NotifyTask $notifications,
    ) {}

    /**
     * 金额使用 ORM 的固定聚合字段表达式；ThinkORM 4 sum() 返回 float，此处保留 MySQL DECIMAL 整数文本。
     * SQL 表达式是代码常量，日期通过 where 绑定；不接受外部字段、表达式或排序。
     *
     * @return array{created:array<int|string,int>,business:array,payments:array,matched_receipts:int,notifications:array<int|string,int>}
     */
    public function day(string $from, string $until, ?string $appId = null): array
    {
        $createdQuery = $this->orders
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until);
        // 单应用下也先限定日期；避免优化器按应用扫描全部历史后再分组。
        $createdQuery->force($appId === null ? 'idx_biz_admin_created' : 'idx_biz_app_created');
        if ($appId !== null) {
            $createdQuery->where('app_id', $appId);
        }
        $created = $createdQuery->group('status')->column('COUNT(*)', 'status');
        $businessQuery = $this->orders
            ->where('status', TradeConstant::ORDER_STATUS_SUCCESS)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $until);
        $businessQuery->force($appId === null ? 'idx_biz_paid' : 'idx_biz_app_paid');
        if ($appId !== null) {
            $businessQuery->where('app_id', $appId);
        }
        $business = $businessQuery
            ->fieldRaw('COUNT(*) AS records, COALESCE(SUM(paid_amount), 0) AS amount')
            ->find();
        // 与 PayOrder 金额读取保持同一口径：成功后 pay_amount 已恢复，识别和实收均从插件元数据读取。
        // 先转 DECIMAL(20,0) 再聚合，避免 JSON 文本参与算术后被 MySQL 隐式转为浮点。
        // 本查询只统计成功单，生命周期已经把 pay_amount 恢复为业务原金额，无需重复提取快照。
        $original = 'CAST(pay_amount AS DECIMAL(20,0))';
        $received =
            "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ext_json, '$.personal_receipt.notified_amount')), 'null') AS DECIMAL(20,0))";
        $receipt =
            "COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ext_json, '$.personal_receipt.receipt_amount')), 'null') AS DECIMAL(20,0)), pay_amount)";
        // 单日成功聚合固定使用状态/付款时间复合索引，避免 5.7 在大量同状态历史下误选状态扫描。
        $paymentQuery = $this->payments
            ->where('status', TradeConstant::ORDER_STATUS_SUCCESS)
            ->force('idx_pay_paid')
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $until);
        $this->limitToApp($paymentQuery, $appId);
        $payments = $paymentQuery
            ->fieldRaw(
                "COUNT(*) AS records, COUNT($received) AS with_amount, COALESCE(SUM($original), 0) AS sum_business_cents,
                COALESCE(SUM($received), 0) AS sum_received_cents, COALESCE(SUM($received - $original), 0) AS sum_difference_cents,
                COALESCE(SUM($receipt - $original), 0) AS sum_offset_cents",
            )
            ->find();
        if ($business === null || $payments === null) {
            throw new RuntimeException('统计结果未返回完整聚合行');
        }
        $notificationQuery = $this->notifications->group('status');
        $this->limitToApp($notificationQuery, $appId);
        $flowQuery = $this->flows->where('paid_at', '>=', $from)->where('paid_at', '<', $until);
        // 所有订单和实际到账使用同一统计口径，按需限定应用归属。
        if ($appId !== null) {
            $flowQuery->whereExists(static function (Query $payments) use ($appId): void {
                $payments
                    ->table('ma_pay_order')
                    ->alias('stats_pay')
                    ->join('ma_biz_order stats_biz', 'stats_biz.biz_no=stats_pay.biz_no')
                    ->whereColumn('stats_pay.pay_no', 'ma_receipt_flow.pay_no')
                    ->where('stats_biz.app_id', $appId)
                    ->fieldRaw('1');
            });
        }
        return [
            'created' => array_map('intval', $created),
            'business' => $business->getData(),
            'payments' => $payments->getData(),
            'matched_receipts' => (int) $flowQuery->count(),
            'notifications' => array_map(
                'intval',
                $notificationQuery->column('COUNT(*)', 'status'),
            ),
        ];
    }

    /** 支付与通知通过业务单归属筛选；不根据商品名称区分统计。 */
    private function limitToApp(Query $query, ?string $appId): void
    {
        // 全部应用无需归属子查询；业务写入已保证支付/通知所属订单存在。
        if ($appId === null) {
            return;
        }
        // 从本次日期/状态范围逐条核对所属应用，不先展开该应用的全部历史业务单。
        $table = $query->getTable();
        $query->whereExists(static function (Query $orders) use ($appId, $table): void {
            $orders
                ->table('ma_biz_order')
                ->alias('stats_biz')
                ->fieldRaw('1')
                ->whereColumn('stats_biz.biz_no', $table . '.biz_no')
                ->where('stats_biz.app_id', $appId);
        });
    }
}
