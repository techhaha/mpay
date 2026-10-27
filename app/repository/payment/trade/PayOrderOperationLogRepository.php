<?php
declare(strict_types=1);

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\model\payment\PayOrderOperationLog;

/**
 * 支付人工动作的强制审计；与状态变更共用事务，审计失败不能提交付款确认。
 *
 * @extends BaseRepository<PayOrderOperationLog>
 */
final class PayOrderOperationLogRepository extends BaseRepository
{
    public function __construct(PayOrderOperationLog $model)
    {
        parent::__construct($model);
    }

    /** @return array{items:list<PayOrderOperationLog>,total:int} 按支付号读取操作审计；不返回全表后截断。 */
    public function pageForPay(string $payNo, int $page, int $limit): array
    {
        return [
            'items' => $this->model
                ->where('pay_no', $payNo)
                ->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->all(),
            'total' => (int) $this->model->where('pay_no', $payNo)->count(),
        ];
    }

    /**
     * @param array{received_amount?:int,paid_at?:string,platform_trade_no?:string|null,
     *     reference?:string,flow_id?:string|null,close_semantics?:string,notify_no?:string,attempt_no?:int,
     *     refund_no?:string,refund_amount?:int} $evidence 脱敏证据，禁止原始平台正文。
     */
    public function record(
        string $payNo,
        string $adminId,
        string $requestId,
        string $reason,
        string $action,
        int $before,
        int $after,
        array $evidence,
    ): void {
        $this->insert(
            [
                'pay_no' => $payNo,
                'admin_user_id' => $adminId,
                'request_id' => $requestId,
                'action' => $action,
                'reason' => $reason,
                'before_status' => $before,
                'after_status' => $after,
                'evidence_json' => json_encode(
                    $evidence,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            ],
            [
                'pay_no',
                'admin_user_id',
                'request_id',
                'action',
                'reason',
                'before_status',
                'after_status',
                'evidence_json',
            ],
        );
    }
}
