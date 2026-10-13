<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use app\common\constant\TradeConstant;
use app\common\util\Clock;
use app\common\util\Money;
use app\common\validation\PluginConfigValidator;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\service\file\FileAssetService;
use app\service\payment\config\PaymentChannelRevisionService;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\receipt\ChannelNotifyContext;
use app\service\payment\receipt\PayOrderCallbackService;
use app\service\payment\receipt\ReceiptScopeSet;
use DateTimeImmutable;
use RuntimeException;
use think\Config;
use Throwable;
use UnexpectedValueException;

/**
 * 按支付单冻结版本装配原插件，在持久执行标记之后、数据库事务之外调用 pay。
 * 只保存标准公开材料；可信成功事实继续进入统一流水链路，不由插件直接改成功状态。
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
        private readonly PluginConfigValidator $validator,
        private readonly PaymentPluginPayResultValidator $results,
    ) {
    }

    /**
     * 重复调用复用已有材料或等待原执行；只有明确声明安全的静态构造可在租约到期后重建。
     * 未预期异常在记录不确定状态后原样抛出，不能吞掉程序故障后报告正常付款材料。
     *
     * @return PayOrder 持久状态，仅供内部映射；调用者不能直接序列化完整模型。
     */
    public function dispatch(string $payNo, DateTimeImmutable $now, string $requestId): PayOrder
    {
        $pay = $this->payOrders->find($payNo) ?? throw new RuntimeException('支付单不存在');
        if (!in_array((int) $pay['status'], [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true)) {
            return $pay;
        }
        $time = $now->format('Y-m-d H:i:s');
        if ($pay['expire_at'] <= $time) {
            $this->lifecycle->expire($pay['biz_no']);
            return $this->payOrders->find($payNo) ?? throw new RuntimeException('到期后的支付单不可读取');
        }
        $execution = $pay['ext_json']['payment_context']['execution'] ?? null;
        if (is_array($execution) && (($execution['phase'] ?? null) === 'presented'
            || ($execution['replay_safe'] ?? null) === false
            || (is_string($execution['lease_until'] ?? null) && $execution['lease_until'] > $time))) {
            // 已展示或原执行仍在处理中时直接复用，避免刷新依赖重新读取配置或重读二维码文件。
            return $pay;
        }
        $version = $pay['ext_json']['personal_receipt']['plugin_version'] ?? null;
        if (!is_string($version)) {
            throw new RuntimeException('订单插件版本快照缺失');
        }
        $plugin = $this->plugins->make($pay['plugin_code']);
        $definition = $this->plugins->describe($plugin);
        // 材料和配置加载失败发生在认领之前，不制造“已调用外部平台”的错误事实。
        $context = $this->context($pay);
        $this->validator->validate($definition->configSchema, $context->configuration);
        if (!in_array($context->payType, $definition->payTypes, true)) {
            throw new RuntimeException('插件实现与原订单来源合同不一致');
        }
        $lease = $this->config->get('payment.execution_lease_seconds', 30);
        if (!is_int($lease)) {
            throw new RuntimeException('执行认领期限必须为整数秒');
        }
        // 插件明确声明重放边界；有外部副作用的插件默认不得因租约到期再次发起交易。
        $claim = $this->lifecycle->beginExecution($payNo, $plugin->canRetryPay(), $lease, $requestId);
        if (!$claim['execute']) {
            return $claim['pay'];
        }
        $token = $claim['token'];
        try {
            // 生命周期的认领事务已经提交；任何 SDK 网络请求均发生在此边界之外。
            // 只注入接口约定的运行参数；通道名称、金额策略等内部列不能覆盖插件同名设置。
            $runtime = [
                'channel_id' => $context->channel['id'],
                'api_config_id' => $context->channel['api_config_id'],
                'receipt_scope_key' => $context->scopeKey,
                'pay_type_code' => $context->payType,
            ];
            // 图片字段由 schema 决定；服务只把受控资产 ID 转成同源地址，不猜测插件的字段名称。
            foreach ($definition->configSchema as $field) {
                if ($field['type'] === 'upload') {
                    $asset = $context->configuration[$field['field']] ?? '';
                    $runtime[$field['field'] . '_url'] = $this->files->publicImagePath($asset === '' ? null : $asset);
                }
            }
            $plugin->init(array_replace($context->configuration, $runtime));
            $order = ['pay_no' => $payNo, 'biz_no' => $context->bizNo, 'pay_type_code' => $context->payType,
                'amount' => $context->payAmount, 'expire_at' => $context->expiresAt->format('Y-m-d H:i:s'), 'extra' => $claim['pay']['ext_json']];
            $result = $this->results->validateResult($plugin->pay($order));
            if ($result['pay_no'] !== $payNo || ($result['status'] === 'success' && $result['paid_amount'] !== $context->payAmount)) {
                throw new UnexpectedValueException('插件结果与当前订单不一致');
            }
            $presentation = null;
            if ($result['status'] === 'pending') {
                if ($result['pay_type'] !== $context->payType) { throw new UnexpectedValueException('插件支付方式不一致'); }
                $presentation = $result['presentation'];
            }
        } catch (PaymentDefinitiveException) {
            return $this->lifecycle->finishExecution($payNo, $token, 'rejected', null, $requestId);
        } catch (PaymentUncertainException) {
            return $this->lifecycle->finishExecution($payNo, $token, 'uncertain', null, $requestId);
        } catch (Throwable $exception) {
            $this->lifecycle->finishExecution($payNo, $token, 'uncertain', null, $requestId);
            throw $exception;
        }
        $finishedAt = $this->clock->now();
        if ($result['status'] === 'pending') {
            return $this->lifecycle->finishExecution($payNo, $token, 'presented', $presentation, $requestId, $result['chan_order_no']);
        }
        $callback = new ChannelNotifyContext($pay['channel_id'], $context->channel['api_config_id'],
            $context->configVersion, $pay['plugin_code'], $version,
            new ReceiptScopeSet([$context->scopeKey => ['pay_type' => $context->payType]]),
            $context->configuration, $finishedAt);
        $this->callbacks->acceptPluginResult($result, $callback, $requestId);
        // 返回生命周期已经提交的实际状态，不能仅依据插件字符串向客户端宣称支付成功。
        return $this->payOrders->find($payNo) ?? throw new RuntimeException('回调后支付单不可读取');
    }

    /** 从原版本重建敏感执行上下文，不使用当前通道或当前账号凭证补缺字段。 */
    private function context(PayOrder $pay): PaymentContext
    {
        $snapshot = $this->revisions->snapshot($pay['channel_id'], $pay['channel_revision_id']);
        $receipt = $pay['ext_json']['personal_receipt'] ?? [];
        $payment = $pay['ext_json']['payment_context'] ?? [];
        if (($snapshot['channel']['id'] ?? null) !== $pay['channel_id']
            || ($snapshot['channel']['receipt_scope_key'] ?? null) !== $pay['receipt_scope_key']
            || ($snapshot['config_version'] ?? null) !== ($receipt['config_version'] ?? null)
            || !is_string($payment['pay_type'] ?? null)) {
            throw new RuntimeException('原支付上下文与通道快照不一致');
        }
        $receiptAmount = (int) $pay->receiptAmount();
        Money::assertRange($receiptAmount);
        if ($receiptAmount < $pay->originalAmount()) { throw new RuntimeException('原付款金额小于订单金额'); }
        return new PaymentContext(
            bizNo: $pay['biz_no'],
            payType: $payment['pay_type'],
            payAmount: $pay->originalAmount(),
            expiresAt: new DateTimeImmutable($pay['expire_at']),
            scopeKey: $pay['receipt_scope_key'],
            configVersion: $receipt['config_version'],
            channel: $snapshot['channel'],
            configuration: $snapshot['configuration'],
        );
    }

}
