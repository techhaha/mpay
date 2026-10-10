<?php
declare(strict_types=1);

namespace app\command;

use app\service\system\runtime\SystemDoctorService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/** 运维只读诊断入口；输出不含配置秘密，退出码区分故障与尚需注意的未验证项。 */
final class SystemDoctorCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('system:doctor')->setDescription('只读诊断依赖、站点、密钥、数据库/迁移及工作者近期心跳');
    }

    /** 0 为本次检查无异常，1 为检查失败，2 为警告；都不能代替真实收款和部署验收。 */
    protected function execute(Input $input, Output $output): int
    {
        $code = 0;
        foreach ($this->app->make(SystemDoctorService::class)->inspect() as $check) {
            $output->writeln('[' . $check['status'] . '] ' . $check['id'] . '：' . $check['message']);
            if ($check['status'] === 'error') {
                $code = 1;
            } elseif ($check['status'] === 'warning' && $code === 0) {
                $code = 2;
            }
        }
        $this->app->log->save();
        return $code;
    }
}
