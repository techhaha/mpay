<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\interface\PaymentInterface;
use app\exception\UnsupportedPaymentOperationException;
use think\Request;
use think\Response;

/**
 * 文档中的完整接口骨架，演示元信息和配置声明；不连接平台，不接受任何交易。
 * 文件保留在 docs 下不会被正式插件扫描，真实适配完成后才可放入支付插件目录。
 */
final class ExamplePayment implements PaymentInterface
{
    /** @var array<string,mixed> 单次执行配置，不回显给页面或日志。 */
    private array $config = [];

    public function getCode(): string { return 'example_receipt'; }
    public function getName(): string { return '插件开发示例'; }
    public function getVersion(): string { return '0.1.0'; }
    public function getDescription(): string { return '接口骨架示例，未实现真实收款协议。'; }
    public function getEnabledPayTypes(): array { return ['alipay']; }

    /** @return list<array<string,mixed>> 字段规则必须与动作中实际读取的类型一致。 */
    public function getConfigSchema(): array
    {
        return [
            ['type' => 'input', 'field' => 'api_token', 'title' => '平台密钥',
                'validate' => ['require', 'length' => [16, 128]], 'secret' => true,
                'help' => '填写平台分配的密钥；此示例不向平台发送请求。'],
        ];
    }

    public function init(#[\SensitiveParameter] array $channelConfig): void
    {
        $this->config = $channelConfig;
    }

    /** 未实现平台幂等合同时不能声明可重放。 */
    public function canRetryPay(): bool { return false; }

    /** @throws UnsupportedPaymentOperationException 示例明确拒绝，不返回虚构付款信息。 */
    public function pay(array $order): array { throw new UnsupportedPaymentOperationException(); }
    public function query(array $order): array { throw new UnsupportedPaymentOperationException(); }
    public function close(array $order): array { throw new UnsupportedPaymentOperationException(); }
    public function refund(array $order): array { throw new UnsupportedPaymentOperationException(); }
    public function notify(Request $request): array { throw new UnsupportedPaymentOperationException(); }
    public function notifySuccess(): string|Response { return 'success'; }
    public function notifyFail(): string|Response { return 'fail'; }
}
