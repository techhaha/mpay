<?php
declare(strict_types=1);

namespace app\repository\payment\receipt;

use app\common\base\BaseRepository;
use app\common\constant\TradeConstant;
use app\model\payment\ReceiptMatchReservation;

/**
 * 金额/备注占用及历史的持久化；插件决定识别分配，服务决定最终确认，本仓储不选择支付候选。
 *
 * @extends BaseRepository<ReceiptMatchReservation>
 */
final class ReceiptMatchReservationRepository extends BaseRepository
{
    public function __construct(ReceiptMatchReservation $model)
    {
        parent::__construct($model);
    }

    /** @return list<string> 仅取付款窗口已到期的支付号；释放服务仍按交易锁序重新核验。 */
    public function dueReleaseNumbers(string $now, int $limit): array
    {
        return $this->model->whereNull('released_at')->where('expire_at', '<=', $now)
            ->order('expire_at,id')->limit($limit)->column('pay_no');
    }

    /**
     * 范围退役前按当前读检查占用责任；调用方已锁全部范围及需检查的流水，不能随后再反向锁订单。
     *
     * @return array{unreleased:bool,expire_at:?string} 最晚付款窗口截止，用于核验最终补查是否覆盖。
     */
    public function retirementState(string $scopeKey): array
    {
        $this->requireTransaction();
        $active = $this->model->where('receipt_scope_key', $scopeKey)->whereNull('released_at')->lock(true)->find();
        $last = $this->model->where('receipt_scope_key', $scopeKey)->order('expire_at', 'desc')->lock(true)->find();
        return ['unreleased' => $active !== null, 'expire_at' => $last === null ? null : $last['expire_at']];
    }

    /**
     * 查询并锁定支付单的唯一占用，包含已释放的历史记录，用于重入时复用原识别值。
     * 调用前已锁 BizOrder、PayOrder、ReceiptScope；未查到不表示其他支付单没有占用。
     */
    public function findForPay(string $payNo): ?ReceiptMatchReservation
    {
        $this->requireTransaction();
        return $this->model->where('pay_no', $payNo)->lock(true)->find();
    }

    /** 插件准备前释放过期或已终态订单的槽；历史行继续保留。 */
    public function releaseExpired(string $scopeKey, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('receipt_scope_key', $scopeKey)->whereNull('released_at')->where(function (\think\db\Query $done) use ($now): void {
            $done->where('expire_at', '<=', $now)->whereOr(function (\think\db\Query $settled): void {
                $settled->whereIn('pay_no', function (\think\db\Query $orders): void {
                    $orders->table('ma_pay_order')->field('pay_no')->whereNotIn('status', [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING]);
                });
            });
        })
            ->update(['released_at' => $now, 'updated_at' => $now]);
    }

    /** 生命周期已经持有订单及范围锁；终态立即释放识别槽，不删除原订单的识别历史。 */
    public function releaseForPay(string $payNo, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('pay_no', $payNo)->whereNull('released_at')->update(['released_at' => $now, 'updated_at' => $now]);
    }

    /**
     * 检查当前活跃备注，已释放记录不继续占用四位码空间；调用方已持有范围锁。
     * 历史记录保留用于回调核对，不能以缓存过期直接删除事实。
     */
    public function hasRemark(string $scopeKey, string $remark): bool
    {
        $this->requireTransaction();
        return $this->model->where('receipt_scope_key', $scopeKey)->where('match_mode', 'remark')->whereNull('released_at')
            ->where('match_value', $remark)->lock(true)->find() !== null;
    }

    /**
     * 唯一键只限制未释放槽位；历史行不删除，金额单位为分，时间为 Asia/Shanghai。
     * 调用方须已持有范围行锁；active_value 由数据库生成，不允许请求自行写入。
     *
     * @param array{receipt_scope_key:string, pay_no:string, match_mode:string, match_value:string,
     *     receipt_amount:int, valid_from:string, expire_at:string} $data
     */
    public function create(array $data): string
    {
        return $this->insert($data, ['receipt_scope_key', 'pay_no', 'match_mode', 'match_value', 'receipt_amount', 'valid_from', 'expire_at']);
    }

    /**
     * 在调用方持有真实范围锁时释放已到付款窗口截止点的槽，历史记录继续保留。
     * 释放只允许金额再次分配，不证明后续同金额到账属于新单；匹配仍需核验付款时间。
     *
     * @param string $now 调用方提供的 Asia/Shanghai 当前时间，格式 Y-m-d H:i:s。
     * @return bool 本次是否实际释放；不存在、已释放或窗口未结束均为 false。
     */
    public function releaseExpiredForPay(string $scopeKey, string $payNo, string $now): bool
    {
        $this->requireTransaction();
        return $this->model->where('receipt_scope_key', $scopeKey)->where('pay_no', $payNo)
            ->whereNull('released_at')->where('expire_at', '<=', $now)
            ->update(['released_at' => $now, 'updated_at' => $now]) === 1;
    }

}
