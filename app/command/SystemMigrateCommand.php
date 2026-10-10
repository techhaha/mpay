<?php
declare(strict_types=1);

namespace app\command;

use app\service\system\install\MigrationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/** 版本迁移的 CLI 边界：解析命令、调用迁移服务并返回退出状态，不混入业务初始化。 */
final class SystemMigrateCommand extends Command
{
    /** 注册到框架控制台；迁移目录固定在项目内，不接受命令行传入任意 PHP 目录。 */
    protected function configure(): void
    {
        $this->setName('system:migrate')->setDescription('空库安装或核对并继续迁移；拒绝无记录的非空库');
    }

    /** 完成全部核对时返回 0，故障返回 1；失败诊断走脱敏日志而非直接输出数据库异常。 */
    protected function execute(Input $input, Output $output): int
    {
        try {
            $versions = $this->app->make(MigrationService::class)->migrate($this->app->getRootPath() . 'database/migrations');
            $output->writeln('迁移完成：' . implode(', ', $versions));
            return 0;
        // CLI 顶层收口异常，保持非零退出信号；不得把部分 DDL 完成报告为迁移成功。
        } catch (\Throwable $e) {
            $this->app->make(\app\common\util\SafeLogger::class)->exception($e);
            $output->writeln('<error>建表未完成，请检查数据库权限和安装进度。详细原因见技术日志。</error>');
            return 1;
        }
    }
}
