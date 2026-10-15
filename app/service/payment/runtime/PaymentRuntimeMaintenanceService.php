<?php
declare(strict_types=1);

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\util\Clock;
use app\common\util\SafeLogger;
use app\exception\BusinessException;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\plugin\PayOrderChannelDispatchService;
use InvalidArgumentException;

/**
 * 有界数据库扫描恢复到期及原执行，识别占用由插件按订单状态及原截止判断；不依赖浏览器访问。
 * 每个动作复用领域事务，整个批次没有外层事务，也不回放不确定且未声明安全的外部操作。
 * 单笔恢复的业务拒绝只记录并跳过；数据库/程序故障仍交由命令非零退出。
 */
final class PaymentRuntimeMaintenanceService extends BaseService
{
    /** 仅保存进程内扫描位置；扫描末尾归零，重启重扫仍由原执行幂等边界保护。 */
    private string $executionAfterId = '0';

    public function __construct(
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderRepository $payOrders,
        private readonly PayOrderLifecycleService $lifecycle,
        private readonly PayOrderChannelDispatchService $dispatch,
        private readonly Clock $clock,
        private readonly SafeLogger $logger,
    ) {
    }

    /**
     * 同一批次可重复运行或由多个进程竞争，状态/绑定/认领仍由数据库锁和条件写入保护。
     *
     * @param int $limit 每类扫描最多处理数量，不是把所有历史一次加载到内存。
     * @param string|null $phase 常驻入口轮换单个阶段，让通知在小批次之间获得处理机会；null 供人工单批调用。
     * @param int $budgetSeconds 只在独立动作之间检查的批次预算，不中断正在提交的事务或 SDK 调用。
     * @return array{expired:int,executions_checked:int,executions_rejected:int,budget_exhausted:bool} checked 含被业务拒绝的检查次数。
     */
    public function runOnce(int $limit = 100, int $budgetSeconds = 30, ?string $phase = null): array
    {
        if ($limit < 1 || $limit > 200 || $budgetSeconds < 1 || $budgetSeconds > 300) {
            throw new InvalidArgumentException('维护批次数量必须为 1～200');
        }
        if ($phase !== null && !in_array($phase, ['expire', 'execution'], true)) { throw new InvalidArgumentException('维护阶段无效'); }
        $started = hrtime(true);
        $outOfTime = static fn (): bool => (hrtime(true) - $started) / 1000000000 >= $budgetSeconds;
        $result = ['expired' => 0, 'executions_checked' => 0, 'executions_rejected' => 0, 'budget_exhausted' => false];
        foreach (($phase === null || $phase === 'expire' ? $this->bizOrders->dueExpirationNumbers($this->clock->now()->format('Y-m-d H:i:s'), $limit) : []) as $number) {
            if ($outOfTime()) {
                return array_replace($result, ['budget_exhausted' => true]);
            }
            $result['expired'] += (int) $this->lifecycle->expire($number);
        }
        if ($phase === 'expire') { return $result; }
        $payments = $this->payOrders->recoverableExecutionPage(
            $this->clock->now()->format('Y-m-d H:i:s'),
            $this->executionAfterId,
            $limit
        );
        foreach ($payments as $payment) {
            if ($outOfTime()) {
                return array_replace($result, ['budget_exhausted' => true]);
            }
            $this->executionAfterId = $payment['id'];
            $requestId = bin2hex(random_bytes(16));
            try {
                $this->dispatch->dispatch($payment['pay_no'], $this->clock->now(), $requestId);
            } catch (BusinessException $error) {
                // 不改变订单状态或重放规则；单笔配置/业务拒绝不能阻断其他订单及商户通知。
                $this->logger->record('payment.execution_rejected', [
                    'pay_no' => $payment['pay_no'],
                    'trace_no' => $requestId,
                    'reason_code' => 'business_rejected',
                ], 'warning');
                $this->logger->exception($error);
                $result['executions_rejected']++;
            }
            $result['executions_checked']++;
        }
        if (count($payments) < $limit) { $this->executionAfterId = '0'; }
        return $result;
    }

}
