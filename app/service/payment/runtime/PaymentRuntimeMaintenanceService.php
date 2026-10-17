<?php
declare(strict_types=1);

namespace app\service\payment\runtime;

use app\common\util\Clock;
use app\common\util\SafeLogger;
use app\repository\payment\trade\BizOrderRepository;
use app\service\payment\order\PayOrderLifecycleService;
use InvalidArgumentException;
use PDOException;
use think\db\exception\DbException;
use Throwable;

/** 分批处理订单到期；每笔复用生命周期事务，不调用插件准备付款。 */
final class PaymentRuntimeMaintenanceService
{
    /** @var array<string,int> 失败单暂缓 60 秒，避免反复报错及占满到期批次；仅保存在当前进程。 */
    private array $retryAt = [];

    public function __construct(
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderLifecycleService $lifecycle,
        private readonly Clock $clock,
        private readonly SafeLogger $logger,
    ) {}

    /**
     * 单笔故障记录后继续，数据库故障上抛。到期状态由生命周期锁内复核。
     * @param int $limit 本次最多读取的到期订单数。
     * @param int $budgetSeconds 只在订单之间检查预算，不中断正在执行的事务。
     * @return int 本次实际标记超时的订单数，不计失败或被并发处理的订单。
     */
    public function runOnce(int $limit = 100, int $budgetSeconds = 30): int
    {
        if ($limit < 1 || $limit > 200 || $budgetSeconds < 1 || $budgetSeconds > 300) {
            throw new InvalidArgumentException('维护批次数量或时间预算超出范围');
        }
        $started = hrtime(true);
        $this->retryAt = array_filter(
            $this->retryAt,
            static fn(int $until): bool => $until > $started,
        );
        $expired = 0;
        foreach (
            $this->bizOrders->dueExpirationNumbers(
                $this->clock->now()->format('Y-m-d H:i:s'),
                $limit,
                array_keys($this->retryAt),
            )
            as $number
        ) {
            if ((hrtime(true) - $started) / 1000000000 >= $budgetSeconds) {
                break;
            }
            try {
                $expired += (int) $this->lifecycle->expire($number);
            } catch (DbException | PDOException $error) {
                throw $error;
            } catch (Throwable $error) {
                $this->retryAt[$number] = hrtime(true) + 60_000_000_000;
                $this->logger->record(
                    'payment.expiration_rejected',
                    ['biz_no' => $number, 'reason_code' => 'task_fault'],
                    'error',
                );
                $this->logger->exception($error);
            }
        }
        return $expired;
    }
}
