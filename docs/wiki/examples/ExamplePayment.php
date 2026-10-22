<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\interface\PaymentInterface;
use app\exception\UnsupportedPaymentOperationException;
use think\Request;
use think\Response;

/** 文档接口骨架；不连接平台，不接受交易，正式插件目录不会扫描此文件。 */
final class ExamplePayment extends BasePayment implements PaymentInterface
{
    protected array $paymentInfo = [
        'code' => 'example_receipt',
        'name' => '插件开发示例',
        'version' => '0.1.0',
        'description' => '接口骨架示例，未实现真实收款协议。',
        'pay_types' => ['alipay'],
        'config_schema' => [
            [
                'type' => 'input',
                'field' => 'api_token',
                'title' => '平台密钥',
                'validate' => ['require', 'length' => [16, 128]],
                'secret' => true,
                'help' => '填写平台分配的密钥；此示例不向平台发送请求。',
            ],
        ],
    ];

    public function pay(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }

    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }

    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }

    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }

    /** 实际插件应返回标准结果列表；此骨架不把未实现或验签失败伪装成空列表。 */
    public function notify(Request|array $payload, array $context): array
    {
        throw new UnsupportedPaymentOperationException();
    }

    public function notifySuccess(): string|Response
    {
        throw new UnsupportedPaymentOperationException();
    }

    public function notifyFail(string $message): string|Response
    {
        throw new UnsupportedPaymentOperationException();
    }
}
