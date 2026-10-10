<?php
declare(strict_types=1);

namespace app\command;

use app\common\util\CliWorkerControl;
use app\common\util\SafeLogger;
use app\service\payment\notify\MerchantNotifyDispatcherService;
use app\service\payment\runtime\PaymentRuntimeMaintenanceService;
use app\service\system\config\SystemConfigService;
use app\service\system\runtime\RuntimePruneService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/** 通知与维护的原生常驻入口；账单采集由 payment:watch 隔离，外部守护负责启动和重启。 */
final class PaymentWorkCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('payment:work')->setDescription('持续派发业务通知并轮换处理订单过期、匹配、恢复和运行文件清理')
            ->addOption('once', null, Option::VALUE_NONE, '只执行有界批次，不常驻')
            ->addOption('limit', null, Option::VALUE_REQUIRED, '单次批次最多轮数（1～1000），每轮一条通知及一个小维护阶段', '100')
            ->addOption('max-seconds', null, Option::VALUE_REQUIRED, '可选运行期限（60～86400 秒）；不指定则持续运行，到期后由守护续启');
    }

    /** 数据库/程序故障非零退出；网络失败由派发服务记录重试，不吞异常后无限空转。 */
    protected function execute(Input $input, Output $output): int
    {
        $control = new CliWorkerControl();
        $owner = bin2hex(random_bytes(16));
        $heartbeat = false;
        try {
            $once = (bool) $input->getOption('once');
            $limit = $this->integerOption($input, 'limit', 1, 1000);
            // 默认持续处理通知；显式期限只在两轮之间检查，当前通知及维护回写完成后才退出。
            $maximum = $input->getOption('max-seconds') === null ? null : $this->integerOption($input, 'max-seconds', 60, 86400);
            $control->acquire($this->app->getRuntimePath(), 'work');
            $graceful = $control->install();
            $output->writeln('通知/维护入口已启动；退出恢复：' . ($graceful ? '信号平滑退出及租约恢复' : '外部守护及租约恢复'));
            $dispatcher = $this->app->make(MerchantNotifyDispatcherService::class);
            $maintenance = $this->app->make(PaymentRuntimeMaintenanceService::class);
            $started = hrtime(true); $round = 0; $idleRounds = 0; $nextPrune = 30;
            $counts = ['acknowledged' => 0, 'retry' => 0, 'exhausted' => 0, 'quarantined' => 0, 'lease_lost' => 0, 'idle' => 0];
            $maintenanceCounts = ['expired' => 0, 'executions_checked' => 0, 'released' => 0];
            $phases = ['expire', 'execution', 'release'];
            while (!$control->stopping() && (!$once || $round < $limit)) {
                $elapsed = (hrtime(true) - $started) / 1000000000;
                if ($maximum !== null && $elapsed >= $maximum) { break; }
                $control->heartbeat($this->app->getRuntimePath(), 'work', $owner, true); $heartbeat = true;
                // 一条通知后轮换一个维护阶段；不先清空全部账务或历史记录才发送业务通知。
                $result = $dispatcher->dispatchOne($owner);
                if (!array_key_exists($result, $counts)) { throw new \LogicException('通知派发返回未知状态'); }
                $counts[$result]++;
                $batch = $maintenance->runOnce(5, 1, $phases[$round % count($phases)]);
                $actions = 0;
                foreach ($maintenanceCounts as $key => $unused) { $maintenanceCounts[$key] += $batch[$key]; $actions += $batch[$key]; }
                $idleRounds = $result === 'idle' && $actions === 0 ? $idleRounds + 1 : 0;
                // 仅通知暂时空闲时进行小批次文件保留清理；交易/流水/审计不在清理范围。
                if ($result === 'idle' && $elapsed >= $nextPrune) {
                    $days = $this->app->make(SystemConfigService::class)->values('maintenance')['technical_log_days'];
                    $this->app->make(RuntimePruneService::class)->run(true, 20, $days, 500);
                    $nextPrune = $elapsed + 3600;
                }
                $this->app->log->save();
                $round++;
                if ($once && $idleRounds >= count($phases)) { break; }
                if (!$once && $result === 'idle') { $control->idle(); }
            }
            $output->writeln(json_encode(['notifications' => $counts, 'maintenance' => $maintenanceCounts], JSON_THROW_ON_ERROR));
            return 0;
        } catch (\Throwable $error) {
            $this->app->make(SafeLogger::class)->exception($error); $this->app->log->save();
            $output->writeln('<error>通知与维护任务异常退出，请检查配置和技术日志。待处理任务将在重新启动后继续处理。</error>');
            return 1;
        } finally {
            try { if ($heartbeat) { $control->heartbeat($this->app->getRuntimePath(), 'work', $owner, false); } }
            finally { $control->restore(); }
        }
    }

    /** 命令参数是字符串，拒绝隐式数值转换及无界批次。 */
    private function integerOption(Input $input, string $name, int $minimum, int $maximum): int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,5}$/D', $value) || (int) $value < $minimum || (int) $value > $maximum) {
            throw new \InvalidArgumentException('工作者参数超出范围：' . $name);
        }
        return (int) $value;
    }
}
