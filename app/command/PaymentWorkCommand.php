<?php
declare(strict_types=1);

namespace app\command;

use app\common\util\CliWorkerControl;
use app\common\util\SafeLogger;
use app\exception\RateLimitStorageException;
use app\service\payment\notify\MerchantNotifyDispatcherService;
use app\service\payment\runtime\PaymentRuntimeMaintenanceService;
use app\service\system\config\SystemConfigService;
use app\service\system\runtime\RuntimePruneService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Throwable;

/** 通知与维护的原生常驻入口；账单采集由 payment:watch 隔离，外部守护负责启动和重启。 */
final class PaymentWorkCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('payment:work')
            ->setDescription('持续派发业务通知、处理订单过期并清理运行文件')
            ->addOption('once', null, Option::VALUE_NONE, '只执行有界批次，不常驻')
            ->addOption(
                'limit',
                null,
                Option::VALUE_REQUIRED,
                '单次批次最多轮数（1～1000），每轮一条通知及一小批到期订单',
                '100',
            )
            ->addOption(
                'max-seconds',
                null,
                Option::VALUE_REQUIRED,
                '可选运行期限（60～86400 秒）；不指定则持续运行，到期后由守护续启',
            );
    }

    /** 订单及单次通知故障在任务边界记录并退避；数据库、进程初始化等共享故障仍非零退出。 */
    protected function execute(Input $input, Output $output): int
    {
        $control = new CliWorkerControl();
        $owner = bin2hex(random_bytes(16));
        $heartbeat = false;
        try {
            $once = (bool) $input->getOption('once');
            $limit = $this->integerOption($input, 'limit', 1, 1000);
            // 默认持续处理通知；显式期限只在两轮之间检查，当前通知及维护回写完成后才退出。
            $maximum =
                $input->getOption('max-seconds') === null
                    ? null
                    : $this->integerOption($input, 'max-seconds', 60, 86400);
            $control->acquire($this->app->getRuntimePath(), 'work');
            $graceful = $control->install();
            $output->writeln(
                '通知/维护入口已启动；退出恢复：' .
                    ($graceful ? '信号平滑退出及租约恢复' : '外部守护及租约恢复'),
            );
            $dispatcher = $this->app->make(MerchantNotifyDispatcherService::class);
            $maintenance = $this->app->make(PaymentRuntimeMaintenanceService::class);
            $started = hrtime(true);
            $round = 0;
            $nextPrune = 30;
            $nextRatePrune = 60;
            $counts = [
                'acknowledged' => 0,
                'retry' => 0,
                'exhausted' => 0,
                'quarantined' => 0,
                'lease_lost' => 0,
                'idle' => 0,
            ];
            $expired = 0;
            while (!$control->stopping() && (!$once || $round < $limit)) {
                $elapsed = (hrtime(true) - $started) / 1000000000;
                if ($maximum !== null && $elapsed >= $maximum) {
                    break;
                }
                $control->heartbeat($this->app->getRuntimePath(), 'work', $owner, true);
                $heartbeat = true;
                // 每条通知后只处理一小批到期订单；付款准备由下单及收银台请求完成。
                $result = $dispatcher->dispatchOne($owner);
                if (!array_key_exists($result, $counts)) {
                    throw new \LogicException('通知派发返回未知状态');
                }
                $counts[$result]++;
                $batchExpired = $maintenance->runOnce(5, 1);
                $expired += $batchExpired;
                // 原生短期缓存仅回收超过最大有效期的旧文件，不增加守护入口或限流锁。
                if ($elapsed >= $nextRatePrune) {
                    $nextRatePrune = $elapsed + 60;
                    try {
                        $this->app->make(RuntimePruneService::class)->pruneAccessCache(20);
                    } catch (RateLimitStorageException) {
                        // 入站保护存储故障不能终止既有商户通知，按维护周期汇总一次诊断。
                        $this->app->make(SafeLogger::class)->rateStorageFailure();
                    }
                }
                // 仅通知暂时空闲时进行小批次文件保留清理；交易/流水/审计不在清理范围。
                if ($result === 'idle' && $elapsed >= $nextPrune) {
                    $days = $this->app->make(SystemConfigService::class)->values('maintenance')[
                        'technical_log_days'
                    ];
                    $nextPrune = $elapsed + 3600;
                    try {
                        $this->app->make(RuntimePruneService::class)->run(true, 20, $days, 500);
                    } catch (Throwable $error) {
                        // 日志/旧心跳清理不是交易前置条件；保留诊断，等下次维护周期再尝试。
                        $this->app->make(SafeLogger::class)->record(
                            'runtime.prune_failed',
                            [
                                'reason_code' => 'cleanup_failed',
                            ],
                            'error',
                        );
                        $this->app->make(SafeLogger::class)->exception($error);
                    }
                }
                $this->app->log->save();
                $round++;
                if ($once && $result === 'idle' && $batchExpired === 0) {
                    break;
                }
                if (!$once && $result === 'idle') {
                    $control->idle();
                }
            }
            $output->writeln(
                json_encode(
                    ['notifications' => $counts, 'maintenance' => ['expired' => $expired]],
                    JSON_THROW_ON_ERROR,
                ),
            );
            return 0;
        } catch (Throwable $error) {
            $this->app->make(SafeLogger::class)->exception($error);
            $this->app->log->save();
            $output->writeln(
                '<error>通知与维护任务异常退出，请检查配置和技术日志。待处理任务将在重新启动后继续处理。</error>',
            );
            return 1;
        } finally {
            try {
                if ($heartbeat) {
                    $control->heartbeat($this->app->getRuntimePath(), 'work', $owner, false);
                }
            } finally {
                $control->restore();
            }
        }
    }

    /** 命令参数是字符串，拒绝隐式数值转换及无界批次。 */
    private function integerOption(Input $input, string $name, int $minimum, int $maximum): int
    {
        $value = $input->getOption($name);
        if (
            !is_string($value) ||
            !preg_match('/^[1-9][0-9]{0,5}$/D', $value) ||
            (int) $value < $minimum ||
            (int) $value > $maximum
        ) {
            throw new \InvalidArgumentException('工作者参数超出范围：' . $name);
        }
        return (int) $value;
    }
}
