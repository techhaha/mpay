<?php
declare(strict_types=1);

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\TradeConstant;
use app\common\util\Clock;
use app\repository\payment\receipt\ReceiptMatchReservationRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\plugin\PayOrderChannelDispatchService;

/**
 * 有界数据库扫描恢复到期及原执行，并释放窗口到期的识别槽；不依赖浏览器访问。
 * 每个动作复用领域事务，整个批次没有外层事务，也不回放不确定且未声明安全的外部操作。
 */
final class PaymentRuntimeMaintenanceService extends BaseService
{
    public function __construct(
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderRepository $payOrders,
        private readonly ReceiptMatchReservationRepository $reservations,
        private readonly ReceiptScopeRepository $scopes,
        private readonly PayOrderLifecycleService $lifecycle,
        private readonly PayOrderChannelDispatchService $dispatch,
        private readonly Clock $clock,
    ) {
    }

    /**
     * 同一批次可重复运行或由多个进程竞争，状态/绑定/认领仍由数据库锁和条件写入保护。
     *
     * @param int $limit 每类扫描最多处理数量，不是把所有历史一次加载到内存。
     * @param string|null $phase 常驻入口轮换单个阶段，让通知在小批次之间获得处理机会；null 供人工单批调用。
     * @param int $budgetSeconds 只在独立动作之间检查的批次预算，不中断正在提交的事务或 SDK 调用。
     * @return array{expired:int,executions_checked:int,released:int,budget_exhausted:bool} checked 是处理数。
     */
    public function runOnce(int $limit = 100, int $budgetSeconds = 30, ?string $phase = null): array
    {
        if ($limit < 1 || $limit > 200 || $budgetSeconds < 1 || $budgetSeconds > 300) {
            throw new \InvalidArgumentException('维护批次数量必须为 1～200');
        }
        if ($phase !== null && !in_array($phase, ['expire', 'execution', 'release'], true)) { throw new \InvalidArgumentException('维护阶段无效'); }
        $started = hrtime(true);
        $outOfTime = static fn (): bool => (hrtime(true) - $started) / 1000000000 >= $budgetSeconds;
        $result = ['expired' => 0, 'executions_checked' => 0, 'released' => 0, 'budget_exhausted' => false];
        foreach (($phase === null || $phase === 'expire' ? $this->bizOrders->dueExpirationNumbers($this->clock->now()->format('Y-m-d H:i:s'), $limit) : []) as $number) {
            if ($outOfTime()) {
                return array_replace($result, ['budget_exhausted' => true]);
            }
            $result['expired'] += (int) $this->lifecycle->expire($number);
        }
        foreach (($phase === null || $phase === 'execution' ? $this->payOrders->recoverableExecutionNumbers($this->clock->now()->format('Y-m-d H:i:s'), $limit) : []) as $number) {
            if ($outOfTime()) {
                return array_replace($result, ['budget_exhausted' => true]);
            }
            $this->dispatch->dispatch($number, $this->clock->now(), bin2hex(random_bytes(16)));
            $result['executions_checked']++;
        }
        foreach (($phase === null || $phase === 'release' ? $this->reservations->dueReleaseNumbers($this->clock->now()->format('Y-m-d H:i:s'), $limit) : []) as $number) {
            if ($outOfTime()) {
                return array_replace($result, ['budget_exhausted' => true]);
            }
            $result['released'] += (int) $this->releaseExpiredForPay($number);
        }
        return $result;
    }

    /**
     * 先通过生命周期处理尚未扫描到的到期单，再按 Biz → Pay → Scope → Reservation 释放。
     * 在锁内复核订单终态及占用窗口，避免与执行或成功确认交错；占用记录保留。
     */
    private function releaseExpiredForPay(string $payNo): bool
    {
        $observed = $this->payOrders->find($payNo) ?? throw new \RuntimeException('识别占用缺少所属支付单');
        $this->lifecycle->expire($observed['biz_no']);
        return $this->transaction(function () use ($observed, $payNo): bool {
            $biz = $this->bizOrders->lock($observed['biz_no']);
            $pay = $this->payOrders->lock($payNo);
            if ($pay['biz_no'] !== $biz['biz_no'] || $biz['active_pay_no'] !== $payNo || (int) $biz['status'] !== (int) $pay['status']) {
                throw new \RuntimeException('释放识别占用时交易关联不一致');
            }
            $this->scopes->lock($pay['receipt_scope_key']);
            $reservation = $this->reservations->findForPay($payNo);
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            if ($reservation === null || $reservation['receipt_scope_key'] !== $pay['receipt_scope_key']) {
                throw new \RuntimeException('支付单缺少原识别占用');
            }
            if (in_array((int) $pay['status'], [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true)
                || $reservation['released_at'] !== null || $reservation['expire_at'] > $now) {
                return false;
            }
            return $this->reservations->releaseExpiredForPay($pay['receipt_scope_key'], $payNo, $now);
        });
    }
}
