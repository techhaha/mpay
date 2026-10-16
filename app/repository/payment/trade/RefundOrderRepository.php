<?php
declare(strict_types=1);

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\common\constant\RefundConstant;
use app\model\payment\RefundOrder;

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

    /** 原订单锁已阻止并发新增/完成；包括未知结果，不能因超时释放可退额度。 */
    public function reservedAmount(string $payNo): int
    {
        $this->requireTransaction();
        return (int) $this->model
            ->where('pay_no', $payNo)
            ->where('status', '<>', RefundConstant::FAILED)
            ->sum('refund_amount');
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
}
