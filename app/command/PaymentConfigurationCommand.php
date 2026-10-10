<?php
declare(strict_types=1);

namespace app\command;

use app\common\util\SafeLogger;
use app\exception\BusinessException;
use app\service\system\audit\ConfigurationActionContext;
use app\service\payment\config\PaymentConfigurationValidator;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** 配置管理的本机入口，完成字段校验和结果输出；事务、授权、版本及范围规则由正式服务统一负责。 */
final class PaymentConfigurationCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('payment:configuration')->setDescription('通过私有 JSON 文件管理插件配置和通道')
            ->addOption('operation', null, Option::VALUE_REQUIRED, 'editor-create/editor-save/plugin-save/channel-enabled/channel-delete')
            ->addOption('input', null, Option::VALUE_REQUIRED, 'public 之外的私有 JSON 文件，凭证不得写进命令行');
    }

    /** 命令只输出公开 ID/版本；schema/业务拒绝返回 2，程序故障记录脱敏诊断并返回 1。 */
    protected function execute(Input $input, Output $output): int
    {
        try {
            $operation = $input->getOption('operation');
            $path = $input->getOption('input');
            if (!is_string($operation) || !is_string($path) || $path === '') { throw new \InvalidArgumentException('必须指定 --operation 和 --input'); }
            $raw = $this->app->make(ConfigurationInputFile::class)->read($path);
            $data = $this->app->make(PaymentConfigurationValidator::class)->validate($operation, $raw);
            $actor = ConfigurationActionContext::cli();
            $result = $this->apply($operation, $data, $actor);
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return 0;
        } catch (BusinessException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            // 验证器只提供代码声明的字段路径和固定说明，使 CLI 可定位错误而不打印配置正文。
            foreach ($exception->errors as $field => $messages) {
                $output->writeln($field . ': ' . implode('；', $messages));
            }
            return 2;
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return 2;
        } catch (\Throwable $exception) {
            $this->app->make(SafeLogger::class)->exception($exception);
            $output->writeln('<error>配置管理未完成，请查看技术日志；未输出平台配置或凭证。</error>');
            return 1;
        }
    }

    /**
     * 只分发已通过 PaymentConfigurationValidator 场景的输入；事务与状态政策仍归服务。
     * operation 使用显式白名单，不把用户字符串解释成方法名。
     *
     * @param array<string,mixed> $data 已核验字段与 JSON 原始类型，嵌套对象已转换为数组。
     * @return array<string,int|string|bool>
     */
    private function apply(string $operation, #[\SensitiveParameter] array $data, ConfigurationActionContext $actor): array
    {
        $service = $this->app->make(\app\service\payment\config\PaymentChannelEditorService::class);
        if ($operation === 'editor-create') { return $service->create($data, $actor); }
        if ($operation === 'editor-save') { return $service->save($data, $actor); }
        if ($operation === 'plugin-save') { return $service->configure($data, $actor); }
        if ($operation === 'channel-enabled') { $service->setEnabled($data, $actor); return ['id'=>$data['id'],'enabled'=>$data['enabled']]; }
        if ($operation === 'channel-delete') { $service->delete($data, $actor); return ['id'=>$data['id']]; }
        throw new \LogicException('已校验管理操作没有对应服务分支');
    }

}
