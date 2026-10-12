<?php
declare(strict_types=1);

namespace app\command;

use app\common\util\CliWorkerControl;
use app\common\util\SafeLogger;
use app\service\payment\collection\ReceiptWatcherService;
use InvalidArgumentException;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Throwable;

/** 采集常驻/单轮的 CLI 边界，与通知进程独立；不在命令中解析平台报文或处理交易规则。 */
final class PaymentWatchCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('payment:watch')
            ->setDescription('按账号采集账单及码牌流水；与通知/维护入口分别守护')
            ->addOption('once', null, Option::VALUE_NONE, '执行一批到期账号后退出')
            ->addOption('limit', null, Option::VALUE_REQUIRED, '单轮最多处理查单/保活批次数（1～1000）', '100')
            ->addOption('max-seconds', null, Option::VALUE_REQUIRED, '可选运行期限（60～86400 秒）；不指定则持续运行，到期后由守护续启');
    }

    /** 已分类平台故障允许其他账号继续；重启从数据库重建待核对订单，登录状态由插件缓存恢复。 */
    protected function execute(Input $input, Output $output): int
    {
        $control = new CliWorkerControl();
        $owner = bin2hex(random_bytes(16));
        $startedHeartbeat = false;
        try {
            $once = (bool) $input->getOption('once');
            $limit = $this->integerOption($input, 'limit', 1, 1000);
            // 未指定期限时持续运行；仅显式参数启用轮次之间的正常退出，不中断正在采集的批次。
            $maximum = $input->getOption('max-seconds') === null ? null : $this->integerOption($input, 'max-seconds', 60, 86400);
            $control->acquire($this->app->getRuntimePath(), 'watch');
            $graceful = $control->install();
            $output->writeln('采集入口已启动；退出恢复：' . ($graceful ? '信号平滑退出及订单恢复' : '外部守护及订单恢复'));
            $watcher = $this->app->make(ReceiptWatcherService::class);
            $started = hrtime(true);
            $counts = ['queried' => 0, 'verified' => 0, 'matched' => 0, 'failed' => 0];
            do {
                if ($control->stopping() || (!$once && $maximum !== null && (hrtime(true) - $started) / 1000000000 >= $maximum)) {
                    break;
                }
                $control->heartbeat($this->app->getRuntimePath(), 'watch', $owner, true);
                $startedHeartbeat = true;
                $heartbeat = fn () => $control->heartbeat($this->app->getRuntimePath(), 'watch', $owner, true);
                $round = $watcher->runRound($limit, $heartbeat);
                foreach ($round as $name => $count) { $counts[$name] += $count; }
                $this->app->log->save();
                if ($once) { break; }
                $interval = (int) $this->app->config->get('receipt.scan_interval_seconds', 3);
                for ($wait = 0; $wait < $interval && !$control->stopping(); $wait++) { $heartbeat(); $control->idle(); }
            } while (true);
            $output->writeln('采集任务已退出，处理汇总：' . json_encode($counts, JSON_THROW_ON_ERROR));
            return 0;
        } catch (Throwable $e) {
            $this->app->make(SafeLogger::class)->exception($e);
            $this->app->log->save();
            $output->writeln('<error>采集工作者未正常完成，请核对参数、配置及脱敏技术日志。</error>');
            return 1;
        } finally {
            try {
                if ($startedHeartbeat) {
                    $control->heartbeat($this->app->getRuntimePath(), 'watch', $owner, false);
                }
            } finally {
                $control->restore();
            }
        }
    }

    /** 控制台参数不能以隐式数值转换扩大单轮或常驻预算。 */
    private function integerOption(Input $input, string $name, int $minimum, int $maximum): int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,5}$/D', $value) || (int) $value < $minimum || (int) $value > $maximum) {
            throw new InvalidArgumentException('采集命令参数超出范围：' . $name);
        }
        return (int) $value;
    }
}
