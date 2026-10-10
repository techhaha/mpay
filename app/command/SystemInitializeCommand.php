<?php
declare(strict_types=1);

namespace app\command;

use app\common\util\SafeLogger;
use app\exception\BusinessException;
use app\service\system\install\InitializationService;
use app\service\system\install\InitializationValidator;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** 已迁移空业务库的 CLI 初始化/状态入口；秘密只能来自私有 JSON，不接受进程参数中的密码或已有 KEY。 */
final class SystemInitializeCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('system:initialize')->setDescription('核对空业务库并初始化管理员、首个接入应用和独立 API KEY；拒绝覆盖现有数据')
            ->addOption('status', null, Option::VALUE_NONE, '只读检查初始化状态')
            ->addOption('input', null, Option::VALUE_REQUIRED, 'public 外私有 JSON：username/password/password_confirmation/app_name，可选 pid');
    }

    /** 成功仅输出公开引用；结果不明先核对 --status 或登录，不引导清空数据库、覆盖密钥或重新安装。 */
    protected function execute(Input $input, Output $output): int
    {
        try {
            $file = $input->getOption('input');
            if ($input->getOption('status')) {
                if ($file !== null) { throw new \InvalidArgumentException('--status 不能与 --input 同时使用'); }
                $result = $this->app->make(InitializationService::class)->status();
            } else {
                if (!is_string($file) || $file === '') { throw new \InvalidArgumentException('请选择 --status 或通过 --input 提供私有初始化文件'); }
                $data = $this->app->make(InitializationValidator::class)->validateInput($this->app->make(ConfigurationInputFile::class)->read($file));
                $result = $this->app->make(InitializationService::class)->initialize($data['username'], $data['password'], $data['app_name'], $data['pid'] ?? null);
                // CLI 安装同样关闭网页入口，不能因安装方式不同留下可写安装表单。
                $this->app->make(\app\service\system\install\InstallationFiles::class)->complete();
            }
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return 0;
        } catch (BusinessException|\InvalidArgumentException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>'); return 2;
        } catch (\Throwable $error) {
            $this->app->make(SafeLogger::class)->exception($error);
            $output->writeln('<error>初始化检查或提交未确认。请查看脱敏日志，并先用 --status 核对；不要清库重装或重置应用凭证。</error>'); return 1;
        }
    }
}
