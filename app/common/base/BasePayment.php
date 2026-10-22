<?php
declare(strict_types=1);

namespace app\common\base;

use app\common\interface\PayPluginInterface;

/** 只复用元信息读取及配置保存；平台请求、订单识别和支付动作由插件实现。 */
abstract class BasePayment implements PayPluginInterface
{
    /** @var array<string,mixed> 插件类声明的元信息，不保存运行时状态。 */
    protected array $paymentInfo = [];

    /** @var array<string,mixed> 本次实例的配置，不向页面或日志输出。 */
    protected array $configuration = [];

    public function init(array $config): void
    {
        $this->configuration = $config;
    }

    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->configuration[$key] ?? $default;
    }

    public function getCode(): string
    {
        return $this->paymentInfo['code'];
    }

    public function getName(): string
    {
        return $this->paymentInfo['name'];
    }

    public function getVersion(): string
    {
        return $this->paymentInfo['version'];
    }

    public function getDescription(): string
    {
        return $this->paymentInfo['description'] ?? '';
    }

    public function getEnabledPayTypes(): array
    {
        return $this->paymentInfo['pay_types'];
    }

    public function getConfigSchema(): array
    {
        return $this->paymentInfo['config_schema'];
    }
}
