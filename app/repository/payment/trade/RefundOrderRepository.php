<?php
declare(strict_types=1);

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\common\constant\RefundConstant;
use app\model\payment\RefundOrder;
use InvalidArgumentException;
use think\db\Query;

/**
 * 退款请求持久化；服务持原支付单锁后调用，串行化同单退款身份与累计金额。
 * @extends BaseRepository<RefundOrder>
 */
final class RefundOrderRepository extends BaseRepository
{
    public function __construct(RefundOrder $model)
    {
        parent::__construct($model);
    }

    public function find(string $number): ?RefundOrder
    {
        return $this->model->where('refund_no', $number)->find();
    }

    public function forRequest(string $payNo, string $key): ?RefundOrder
    {
        $this->requireTransaction();
        return $this->model
            ->where('pay_no', $payNo)
            ->where('request_key', $key)
            ->lock(true)
            ->find();
    }

    /**
     * 原支付单锁内一次汇总额度和未决申请；未知结果不能因超时释放额度。
     *
     * @return array{reserved_amount:int,has_unresolved:bool}
     */
    public function reservation(string $payNo): array
    {
        $this->requireTransaction();
        $failed = RefundConstant::FAILED;
        $processing = RefundConstant::PROCESSING;
        $unknown = RefundConstant::UNKNOWN;
        $totals = $this->model
            ->where('pay_no', $payNo)
            ->fieldRaw(
                "SUM(CASE WHEN status <> {$failed} THEN refund_amount ELSE 0 END) AS reserved_amount,
                MAX(CASE WHEN status IN ({$processing}, {$unknown}) THEN 1 ELSE 0 END) AS has_unresolved",
            )
            ->find();
        return [
            'reserved_amount' => (int) ($totals['reserved_amount'] ?? 0),
            'has_unresolved' => (int) ($totals['has_unresolved'] ?? 0) === 1,
        ];
    }

    /** @param array{refund_no:string,pay_no:string,request_key:string,refund_amount:int} $data */
    public function create(array $data): void
    {
        $this->insert($data, ['refund_no', 'pay_no', 'request_key', 'refund_amount']);
    }

    public function finish(
        string $number,
        int $status,
        ?string $channelNumber,
        string $result,
        string $now,
    ): void {
        $this->requireTransaction();
        $updated = $this->model
            ->where('refund_no', $number)
            ->where('status', RefundConstant::PROCESSING)
            ->update([
                'status' => $status,
                'channel_refund_no' => $channelNumber,
                'result_code' => $result,
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new \RuntimeException('退款记录完成状态冲突');
        }
    }

    /** @return list<RefundOrder> */
    public function forPayment(string $payNo): array
    {
        return $this->model->where('pay_no', $payNo)->order('id', 'desc')->select()->all();
    }

    /**
     * 一页订单只查一次退款汇总；没有申请的支付单不产生记录，完整申请仍由详情读取。
     *
     * @param list<string> $payNos
     * @return array<string,array{count:int,refunded:int,processing:int,unknown:int}>
     */
    public function summaries(array $payNos): array
    {
        if ($payNos === []) {
            return [];
        }
        $result = [];
        foreach ($this->summaryQuery($this->model->whereIn('pay_no', $payNos))->select() as $row) {
            $result[$row['pay_no']] = [
                'count' => (int) $row['refund_count'],
                'refunded' => (int) $row['refunded_amount'],
                'processing' => (int) $row['processing_amount'],
                'unknown' => (int) $row['unknown_amount'],
            ];
        }
        return $result;
    }

    /** 相关子查询只检查当前 active_pay_no；paid_only 用 NOT EXISTS 排除非失败申请，其余按原单汇总。 */
    public function filterForOrder(Query $query, string $status): void
    {
        $query
            ->table('ma_refund_order')
            ->whereColumn('ma_refund_order.pay_no', 'ma_biz_order.active_pay_no');
        if ($status === 'paid_only') {
            $query->fieldRaw('1')->where('ma_refund_order.status', '<>', RefundConstant::FAILED);
            return;
        }
        if (in_array($status, ['refund_full', 'refund_partial'], true)) {
            $query->join(
                'ma_pay_order refund_payment',
                'refund_payment.pay_no=ma_refund_order.pay_no',
            );
        }
        // 每组只有一个原支付单；MAX 取同一实付值以满足聚合规则，不累计实付金额。
        $received =
            "MAX(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(refund_payment.ext_json, '$.personal_receipt.notified_amount')) AS UNSIGNED), 0))";
        $settled = 'unknown_amount = 0 AND processing_amount = 0';
        $condition = match ($status) {
            'refund_unknown' => 'unknown_amount > 0',
            'refund_processing' => 'unknown_amount = 0 AND processing_amount > 0',
            'refund_full' => $settled .
                ' AND refunded_amount > 0 AND refunded_amount = ' .
                $received,
            'refund_partial' => $settled .
                ' AND refunded_amount > 0 AND refunded_amount <> ' .
                $received,
            'refund_failed' => $settled . ' AND refunded_amount = 0',
            default => throw new InvalidArgumentException('未知订单退款筛选'),
        };
        $this->summaryQuery($query)->having($condition);
    }

    /** 调用者先限定支付号范围；列表展示与状态筛选共用金额聚合，不另存汇总字段。 */
    private function summaryQuery(Query $query): Query
    {
        $success = RefundConstant::SUCCESS;
        $processing = RefundConstant::PROCESSING;
        $unknown = RefundConstant::UNKNOWN;
        return $query
            ->field('ma_refund_order.pay_no')
            ->fieldRaw(
                "COUNT(*) AS refund_count,
                SUM(CASE WHEN ma_refund_order.status = {$success} THEN ma_refund_order.refund_amount ELSE 0 END) AS refunded_amount,
                SUM(CASE WHEN ma_refund_order.status = {$processing} THEN ma_refund_order.refund_amount ELSE 0 END) AS processing_amount,
                SUM(CASE WHEN ma_refund_order.status = {$unknown} THEN ma_refund_order.refund_amount ELSE 0 END) AS unknown_amount",
            )
            ->group('ma_refund_order.pay_no');
    }
}
