<?php
declare(strict_types=1);

namespace app\command;

use app\common\util\SafeLogger;
use app\service\system\runtime\RuntimePruneService;
use app\service\system\config\SystemConfigService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/** 非关键运行文件的预览/显式清理入口；不接受任意目录，不提供删除交易事实的选项。 */
final class SystemPruneCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('system:prune')
            ->setDescription('预览旧技术日志及已退出工作者心跳；只有 --execute 才清理')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只列候选（默认）')
            ->addOption(
                'execute',
                null,
                Option::VALUE_NONE,
                '实际删除本次仍符合边界的非关键运行文件',
            )
            ->addOption('limit', null, Option::VALUE_REQUIRED, '最多处理候选数（1～5000）', '500')
            ->addOption(
                'log-days',
                null,
                Option::VALUE_REQUIRED,
                '显式覆盖本次技术日志保留期（7～365 天）；省略时读取后台设置',
            );
    }

    /** 文件名以 JSON 转义输出；异常只记录安全分类，发生部分清理时不声称文件操作能够回滚。 */
    protected function execute(Input $input, Output $output): int
    {
        try {
            $limit = $input->getOption('limit');
            if (
                ($input->getOption('execute') && $input->getOption('dry-run')) ||
                !is_string($limit) ||
                !preg_match('/^[1-9][0-9]{0,3}$/D', $limit) ||
                (int) $limit > 5000
            ) {
                throw new \InvalidArgumentException('清理模式冲突或批次数量无效');
            }
            $days = $input->getOption('log-days');
            if (
                $days !== null &&
                (!is_string($days) ||
                    !preg_match('/^[1-9][0-9]{0,2}$/D', $days) ||
                    (int) $days < 7 ||
                    (int) $days > 365)
            ) {
                throw new \InvalidArgumentException('技术日志保留期无效');
            }
            // 数据库不可读时默认停止，不能悄悄退回较短保留期；运维可显式指定天数以执行离线预览/清理。
            $logDays =
                $days === null
                    ? $this->app->make(SystemConfigService::class)->values('maintenance')[
                        'technical_log_days'
                    ]
                    : (int) $days;
            $result = $this->app
                ->make(RuntimePruneService::class)
                ->run((bool) $input->getOption('execute'), (int) $limit, $logDays);
            $result['technical_log_days'] = $logDays;
            $output->writeln(
                json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
            );
            return 0;
        } catch (\Throwable $e) {
            $this->app->make(SafeLogger::class)->exception($e);
            $this->app->log->save();
            $output->writeln(
                '<error>预览或清理未完成。执行模式可能已处理部分文件，请核对脱敏日志后重新预览。</error>',
            );
            return 1;
        }
    }
}
