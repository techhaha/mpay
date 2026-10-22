<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use app\common\constant\PaymentResultCode as Result;
use app\common\constant\TradeConstant;
use app\common\util\Clock;
use app\common\util\Money;
use app\common\util\SafeLogger;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\service\file\FileAssetService;
use app\service\payment\config\PaymentChannelRevisionService;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\receipt\ChannelNotifyContext;
use app\service\payment\receipt\PayOrderCallbackService;
use DateTimeImmutable;
use RuntimeException;
use think\Config;
use Throwable;
use UnexpectedValueException;

/**
 * 按支付单冻结版本装配原插件，在持久执行标记之后、数据库事务之外调用 pay。
 * 只保存标准公开材料；可信成功事实进入统一回调编排，不由插件直接改成功状态。
 */
final class PayOrderChannelDispatchService
{
    public function __construct(
        private readonly PayOrderRepository $payOrders,
        private readonly PaymentChannelRevisionService $revisions,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly PayOrderLifecycleService $lifecycle,
        private readonly PayOrderCallbackService $callbacks,
        private readonly FileAssetService $files,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly PaymentPluginPayResultValidator $results,
        private readonly SafeLogger $logger,
    ) {}

    /**
     * 重复调用复用已有材料或等待原执行；未完成的准备可在执行租约到期后由普通请求重新发起。
     * 未预期异常在记录不确定状态后原样抛出，不能吞掉程序故障后报告正常付款材料。
     *
     * @return PayOrder 持久状态，仅供内部映射；调用者不能直接序列化完整模型。
     */
    public function dispatch(string $payNo, DateTimeImmutable $now, string $requestId): PayOrder
    {
        $pay = $this->payOrders->find($payNo) ?? throw new RuntimeException('支付单不存在');
        if (
            !in_array(
                (int) $pay['status'],
                [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING],
                true,
            )
        ) {
            return $pay;
        }
        $time = $now->format('Y-m-d H:i:s');
        if ($pay['expire_at'] <= $time) {
            $this->lifecycle->expire($pay['biz_no']);
            return $this->payOrders->find($payNo) ??
                throw new RuntimeException('到期后的支付单不可读取');
        }
        $execution = $pay['ext_json']['payment_context']['execution'] ?? null;
        if (
            is_array($execution) &&
            (($execution['phase'] ?? null) === 'presented' ||
                (is_string($execution['lease_until'] ?? null) && $execution['lease_until'] > $time))
        ) {
            // 已展示或原执行仍在处理中时直接复用，避免刷新依赖重新读取配置或重读二维码文件。
            return $pay;
        }
        $definition = $this->plugins->definition($pay['plugin_code']);
        // 材料和配置加载失败发生在认领之前，不制造“已调用外部平台”的错误事实。
        $context = $this->context($pay);
        if (!in_array($context->payType, $definition->payTypes, true)) {
            throw new RuntimeException('插件实现与原订单来源合同不一致');
        }
        // 上传材料只派生为动作参数，不修改保存配置；回调和退款不依赖图片文件。
        $assets = [];
        foreach ($definition->configSchema as $field) {
            if ($field['type'] === 'upload') {
                $asset = $context->configuration[$field['field']] ?? '';
                if (!is_string($asset)) {
                    throw new RuntimeException('插件上传材料字段必须为字符串');
                }
                $assets[$field['field']] = $this->files->publicImagePath($asset === '' ? null : $asset);
            }
        }
        $plugin = $this->plugins->initialize($pay['plugin_code'], $context->configuration);
        $biz = $pay->bizOrder;
        if ($biz === null || $biz['active_pay_no'] !== $payNo) {
            throw new RuntimeException('支付单的业务关联失效');
        }
        $submitType = $biz['ext_json']['submit_type'] ?? null;
        $device = $pay['ext_json']['payment_context']['device'] ?? 'pc';
        if (!in_array($submitType, ['page', 'api'], true)) {
            throw new RuntimeException('原订单缺少有效提交来源');
        }
        // 通用对接参数由服务从原业务单生成；插件不需要为取商品名或回跳凭证查询业务表。
        $notifyUrl = (string) url('/api/pay/' . $payNo . '/notify')->suffix(false)->domain(true)->root('');
        $returnUrl = (string) url('/cashier/pay', [
            'biz_no' => $biz['biz_no'],
            'token' => $biz['ext_json']['cashier_token'],
        ])->suffix(false)->domain(true)->root('');
        $lease = $this->config->get('payment.execution_lease_seconds', 30);
        if (!is_int($lease)) {
            throw new RuntimeException('执行认领期限必须为整数秒');
        }
        // 保留执行认领，串行化普通请求；中断后仍使用原支付单、配置及付款窗口重新准备。
        $claim = $this->lifecycle->beginExecution(
            $payNo,
            $lease,
            $requestId,
        );
        if (!$claim['execute']) {
            return $claim['pay'];
        }
        $token = $claim['token'];
        try {
            // 生命周期的认领事务已经提交；任何 SDK 网络请求均发生在此边界之外。
            $order = [
                'pay_no' => $payNo,
                'biz_no' => $context->bizNo,
                'channel_id' => $pay['channel_id'],
                'pay_type_code' => $context->payType,
                'amount' => $context->payAmount,
                'expire_at' => $context->expiresAt->format('Y-m-d H:i:s'),
                'subject' => $biz['subject'],
                'client_ip' => $biz['client_ip'],
                'notify_url' => $notifyUrl,
                'return_url' => $returnUrl,
                '_env' => $device,
                '_submit_type' => $submitType,
                'assets' => $assets,
                'extra' => $claim['pay']['ext_json'],
            ];
            $result = $this->results->validateResult($plugin->pay($order));
            if (
                $result['pay_no'] !== $payNo ||
                ($result['status'] === 'success' && $result['paid_amount'] !== $context->payAmount)
            ) {
                throw new UnexpectedValueException('插件结果与当前订单不一致');
            }
            $presentation = null;
            if ($result['status'] === 'pending') {
                if ($result['pay_type'] !== $context->payType) {
                    throw new UnexpectedValueException('插件支付方式不一致');
                }
                $presentation = $result['presentation'];
            }
        } catch (PaymentDefinitiveException | PaymentUncertainException $exception) {
            $rejected = $exception instanceof PaymentDefinitiveException;
            // 插件/SDK 先脱敏，日志复用既有截断；这里只记录本次调用诊断，最终状态仍由生命周期核对。
            $this->logger->record('payment.execution_error', [
                'pay_no' => $payNo,
                'channel_id' => $pay['channel_id'],
                'plugin_code' => $pay['plugin_code'],
                'trace_no' => $requestId,
                'reason_code' => $rejected ? Result::EXECUTION_REJECTED : Result::EXECUTION_UNCERTAIN,
                'error_message' => $exception->getMessage(),
            ], 'warning');
            return $this->lifecycle->finishExecution(
                $payNo,
                $token,
                $rejected ? 'rejected' : 'uncertain',
                null,
                $requestId,
            );
        } catch (Throwable $exception) {
            $this->lifecycle->finishExecution($payNo, $token, 'uncertain', null, $requestId);
            throw $exception;
        }
        if ($result['status'] === 'pending') {
            return $this->lifecycle->finishExecution(
                $payNo,
                $token,
                'presented',
                $presentation,
                $requestId,
                $result['chan_order_no'],
            );
        }
        $callback = new ChannelNotifyContext(
            $pay['channel_id'],
            $context->channel['api_config_id'],
            $context->configVersion,
            $pay['plugin_code'],
            $pay['ext_json']['personal_receipt']['plugin_version'],
            $context->scopeKey,
            $context->payType,
            $context->configuration,
            $this->clock->now(),
            $payNo,
        );
        $this->callbacks->acceptPluginResult($result, $callback, $requestId);
        // 返回生命周期已经提交的实际状态，不能仅依据插件字符串向客户端宣称支付成功。
        return $this->payOrders->find($payNo) ?? throw new RuntimeException('回调后支付单不可读取');
    }

    /** 从原版本重建敏感执行上下文，不使用当前通道或当前账号凭证补缺字段。 */
    private function context(PayOrder $pay): PaymentContext
    {
        $snapshot = $this->revisions->forOrder($pay);
        $payment = $pay['ext_json']['payment_context'] ?? [];
        if (!is_string($payment['pay_type'] ?? null)) {
            throw new RuntimeException('原支付上下文与通道快照不一致');
        }
        $receiptAmount = (int) $pay->receiptAmount();
        Money::assertRange($receiptAmount);
        if ($receiptAmount < $pay->originalAmount()) {
            throw new RuntimeException('原付款金额小于订单金额');
        }
        return new PaymentContext(
            bizNo: $pay['biz_no'],
            payType: $payment['pay_type'],
            payAmount: $pay->originalAmount(),
            expiresAt: new DateTimeImmutable($pay['expire_at']),
            scopeKey: $pay['receipt_scope_key'],
            configVersion: $snapshot['config_version'],
            channel: $snapshot['channel'],
            configuration: $snapshot['configuration'],
        );
    }
}
