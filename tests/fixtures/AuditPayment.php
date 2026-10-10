<?php
declare(strict_types=1);

use app\common\interface\PaymentInterface;
use app\common\payment\WechatReceiptPayment;
use app\common\util\Clock;
use app\repository\payment\trade\PayOrderRepository;
use think\Request;
use think\Response;

/** 仅由 CLI 隔离注册表加载的合成插件；没有平台资金接口，不进入 app/common/payment 的正式目录。 */
final class AuditPayment implements PaymentInterface
{
    public static string $payMode = 'success';
    public static string $refundMode = 'success';
    public static int $payCalls = 0;
    public static int $refundCalls = 0;

    public function __construct(private readonly WechatReceiptPayment $receipt, private readonly PayOrderRepository $orders, private readonly Clock $clock) {}
    public function init(array $channelConfig): void { $this->receipt->init($channelConfig); }
    public function getCode(): string { return 'audit_payment'; }
    public function getName(): string { return '隔离链路插件'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getEnabledPayTypes(): array { return ['wxpay']; }
    public function getDescription(): string { return ''; }
    public function getConfigSchema(): array { return $this->receipt->getConfigSchema(); }
    public function canRetryPay(): bool { return self::$payMode !== 'uncertain'; }

    public function pay(array $order): array
    {
        if (think\facade\Db::connect()->connect()->inTransaction()) { throw new RuntimeException('插件执行不能持业务事务'); }
        self::$payCalls++;
        if (self::$payMode === 'uncertain') { throw new app\exception\PaymentUncertainException(); }
        $pending = $this->receipt->pay($order);
        if (self::$payMode === 'pending') { return array_replace($pending, ['chan_order_no' => 'fixture-order-' . $order['pay_no']]); }
        $pay = $this->orders->find($order['pay_no']);
        return $this->receipt->notifyPayload(['record' => ['pay_type' => 'wxpay', 'event_key' => 'fixture-' . $order['pay_no'],
            'trade_no' => 'fixture-' . $order['pay_no'], 'amount' => $pay->receiptAmount(),
            'paid_at' => $this->clock->now()->format('Y-m-d H:i:s'), 'remark' => $pay['ext_json']['personal_receipt']['remark']]]);
    }

    public function refund(array $order): array
    {
        if (think\facade\Db::connect()->connect()->inTransaction()) { throw new RuntimeException('退款调用不能持业务事务'); }
        self::$refundCalls++;
        if (self::$refundMode === 'unsupported') { throw new app\exception\UnsupportedPaymentOperationException(); }
        if (self::$refundMode === 'fault') { throw new RuntimeException('synthetic failure'); }
        $amount = self::$refundMode === 'invalid' ? $order['refund_amount'] + 1 : $order['refund_amount'];
        return ['status' => self::$refundMode === 'invalid' ? 'success' : self::$refundMode, 'refund_no' => $order['refund_no'],
            'pay_no' => $order['pay_no'], 'refund_amount' => $amount, 'chan_refund_no' => 'fixture-' . $order['refund_no']];
    }
    public function query(array $order): array { return $this->receipt->query($order); }
    public function close(array $order): array { return $this->receipt->close($order); }
    public function notify(Request $request): array { throw new app\exception\UnsupportedPaymentOperationException(); }
    public function notifySuccess(): string|Response { return 'success'; }
    public function notifyFail(): string|Response { return 'fail'; }
}
