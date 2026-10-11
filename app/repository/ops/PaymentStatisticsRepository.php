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
    public function __construct(private readonly BizOrder $orders, private readonly PayOrder $payments,
        private readonly ReceiptFlow $flows, private readonly NotifyTask $notifications)
    {
    }

    /**
     * 金额使用 ORM 的固定聚合字段表达式；ThinkORM 4 sum() 返回 float，此处保留 MySQL DECIMAL 整数文本。
     * SQL 表达式是代码常量，日期通过 where 绑定；不接受外部字段、表达式或排序。
     *
     * @return array{created:array<int|string,int>,business:array,payments:array,matched_receipts:int,notifications:array<int|string,int>}
     */
    public function day(string $from, string $until, ?string $appId = null): array
    {
        $createdQuery = $this->orders->where('created_at', '>=', $from)->where('created_at', '<', $until);
        if ($appId !== null) { $createdQuery->where('app_id', $appId); }
        $created = $createdQuery->group('status')->column('COUNT(*)', 'status');
        $businessQuery = $this->orders->where('status', TradeConstant::ORDER_STATUS_SUCCESS)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $until);
        if ($appId !== null) { $businessQuery->where('app_id', $appId); }
        $business = $businessQuery->fieldRaw('COUNT(*) AS records, COALESCE(SUM(paid_amount), 0) AS amount')->find();
        // 与 PayOrder 金额读取保持同一口径：成功后 pay_amount 已恢复，识别和实收均从插件元数据读取。
        // 先转 DECIMAL(20,0) 再聚合，避免 JSON 文本参与算术后被 MySQL 隐式转为浮点。
        $original = "COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ext_json, '$.personal_receipt.original_amount')), 'null') AS DECIMAL(20,0)), pay_amount)";
        $received = "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ext_json, '$.personal_receipt.notified_amount')), 'null') AS DECIMAL(20,0))";
        $receipt = "COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ext_json, '$.personal_receipt.receipt_amount')), 'null') AS DECIMAL(20,0)), pay_amount)";
        $paymentQuery = $this->payments->where('status', TradeConstant::ORDER_STATUS_SUCCESS)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $until);
        $this->limitToApp($paymentQuery, $appId);
        $payments = $paymentQuery->fieldRaw("COUNT(*) AS records, COUNT($received) AS with_amount, COALESCE(SUM($original), 0) AS sum_business_cents,
                COALESCE(SUM($received), 0) AS sum_received_cents, COALESCE(SUM($received - $original), 0) AS sum_difference_cents,
                COALESCE(SUM($receipt - $original), 0) AS sum_offset_cents")->find();
        if ($business === null || $payments === null) { throw new RuntimeException('统计结果未返回完整聚合行'); }
        $notificationQuery = $this->notifications->group('status');
        $this->limitToApp($notificationQuery, $appId);
        $flowQuery = $this->flows->where('paid_at', '>=', $from)->where('paid_at', '<', $until);
        // 所有订单和实际到账使用同一统计口径，按需限定应用归属。
        $flowQuery->whereIn('pay_no', static function (Query $payments) use ($appId): void {
            $payments->table('ma_pay_order')->field('pay_no')->whereIn('biz_no', static function (Query $orders) use ($appId): void {
                $orders->table('ma_biz_order')->field('biz_no');
                if ($appId !== null) { $orders->where('app_id', $appId); }
            });
        });
        return ['created' => array_map('intval', $created), 'business' => $business->getData(), 'payments' => $payments->getData(),
            'matched_receipts' => (int) $flowQuery->count(),
            'notifications' => array_map('intval', $notificationQuery->column('COUNT(*)', 'status'))];
    }

    /** 支付与通知通过业务单归属筛选；不根据商品名称区分统计。 */
    private function limitToApp(Query $query, ?string $appId): void
    {
        $query->whereIn('biz_no', static function (Query $orders) use ($appId): void {
            $orders->table('ma_biz_order')->field('biz_no');
            if ($appId !== null) { $orders->where('app_id', $appId); }
        });
    }
}
