<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\constant\PaymentResultCode as Result;
use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant as Code;
use app\common\constant\RefundConstant as State;
use app\common\constant\TradeConstant;
use app\common\util\BusinessNumber;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\payment\config\PaymentChannelRevisionService;
use app\service\payment\plugin\PaymentPluginFactoryService;

/**
 * 退款由原订单插件执行：先持久化唯一请求，再在事务外调用平台，最后记录明确结果。
 * 相同请求只读取原结果；处理中、网络未知及进程中断均不自动重发，避免重复退款。
 */
final class RefundService extends BaseService
{
    public function __construct(
        private readonly BizOrderRepository $business,
        private readonly PayOrderRepository $payments,
        private readonly RefundOrderRepository $refunds,
        private readonly PaymentChannelRevisionService $revisions,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly BusinessNumber $numbers,
        private readonly Clock $clock,
    ) {}

    /**
     * 仅接收已认证应用与校验后的请求。原单锁保护幂等和部分退款总额，不包含外部请求。
     * 未指定 refund_no 使用原订单唯一默认请求；多次部分退款必须提供不同的商户退款号。
     */
    public function refund(string $appId, string $payNo, int $amount, ?string $requestNo): RefundOrder
    {
        $pay = $this->payments->find($payNo) ?? throw new BusinessException('订单不存在', Code::NOT_FOUND);
        $claim = $this->transactionRetry(function () use ($appId, $pay, $amount, $requestNo): array {
            $biz = $this->business->lock($pay['biz_no']);
            $locked = $this->payments->lock($pay['pay_no']);
            if ($biz['app_id'] !== $appId || $biz['active_pay_no'] !== $locked['pay_no']) {
                throw new BusinessException('订单不存在', Code::NOT_FOUND);
            }
            if ((int) $biz['status'] !== TradeConstant::ORDER_STATUS_SUCCESS || (int) $locked['status'] !== TradeConstant::ORDER_STATUS_SUCCESS) {
                throw new BusinessException('订单尚未支付成功', Code::CONFLICT);
            }
            $key = $requestNo === null ? 'default' : 'merchant:' . $requestNo;
            $existing = $this->refunds->forRequest($locked['pay_no'], $key);
            if ($existing !== null) {
                if ((int) $existing['refund_amount'] !== $amount) { throw new BusinessException('同一退款号的金额不一致', Code::CONFLICT); }
                return ['execute' => false, 'refund' => $existing, 'pay' => $locked];
            }
            if ($amount < 1 || $this->refunds->reservedAmount($locked['pay_no']) + $amount > $locked->originalAmount()) {
                throw new BusinessException('退款金额超过订单可退金额', Code::CONFLICT);
            }
            $number = $this->numbers->insert('R', $this->clock->now(), fn (string $number) => $this->refunds->create([
                'refund_no' => $number, 'pay_no' => $locked['pay_no'], 'request_key' => $key, 'refund_amount' => $amount,
            ]));
            return ['execute' => true, 'refund' => $this->refunds->find($number), 'pay' => $locked];
        });
        $refund = $claim['refund'];
        if (!$claim['execute']) { return $refund; }
        $pay = $claim['pay'];
        $status = State::UNKNOWN; $channelNumber = null; $reason = Result::REFUND_UNKNOWN; $fault = null;
        try {
            // 配置和平台单号取自原订单，当前通道换插件/停用不改变退款目标。
            $snapshot = $this->revisions->snapshot($pay['channel_id'], $pay['channel_revision_id']);
            if (($snapshot['channel']['plugin_code'] ?? null) !== $pay['plugin_code']) { throw new \RuntimeException('原退款插件快照不一致'); }
            $plugin = $this->plugins->initialize($pay['plugin_code'], $snapshot['configuration'],
                $snapshot['channel'] + ['channel_id' => $pay['channel_id']]);
            $result = $plugin->refund(['refund_no' => $refund['refund_no'], 'pay_no' => $pay['pay_no'],
                'refund_amount' => $amount, 'chan_order_no' => $pay['channel_order_no'],
                'chan_trade_no' => $pay['channel_trade_no'], 'extra' => $pay['ext_json']]);
            if (($result['refund_no'] ?? null) !== $refund['refund_no'] || ($result['pay_no'] ?? null) !== $pay['pay_no']
                || ($result['refund_amount'] ?? null) !== $amount || !array_key_exists('chan_refund_no', $result)
                || ($result['chan_refund_no'] !== null && (!is_string($result['chan_refund_no']) || $result['chan_refund_no'] === '' || strlen($result['chan_refund_no']) > 128))
                || !in_array($result['status'] ?? null, ['success', 'pending', 'unknown', 'failed'], true)) {
                throw new \UnexpectedValueException('插件退款结果与原请求不一致');
            }
            $status = match ($result['status']) {'success' => State::SUCCESS, 'failed' => State::FAILED, 'pending' => State::PROCESSING, default => State::UNKNOWN};
            $channelNumber = $result['chan_refund_no'];
            $reason = match ($result['status']) { 'success' => Result::REFUND_SUCCESS, 'pending' => Result::REFUND_PENDING, 'failed' => Result::REFUND_FAILED, default => Result::REFUND_UNKNOWN };
        } catch (UnsupportedPaymentOperationException) {
            $status = State::FAILED; $reason = Result::REFUND_UNSUPPORTED;
        } catch (PaymentDefinitiveException) {
            $status = State::FAILED; $reason = Result::REFUND_REJECTED;
        } catch (PaymentUncertainException) {
            $reason = Result::REFUND_UNKNOWN;
        } catch (\Throwable $error) {
            // 不把 SDK/程序异常文本存进公开退款信息；不明结果继续占用额度并保留原异常供统一日志处理。
            $reason = Result::REFUND_FAULT; $fault = $error;
        }
        $this->transactionRetry(function () use ($pay, $refund, $status, $channelNumber, $reason): void {
            $this->business->lock($pay['biz_no']);
            $this->payments->lock($pay['pay_no']);
            $this->refunds->finish($refund['refund_no'], $status, $channelNumber, $reason, $this->clock->now()->format('Y-m-d H:i:s'));
        });
        if ($fault !== null) { throw $fault; }
        return $this->refunds->find($refund['refund_no']) ?? throw new \RuntimeException('退款记录缺失');
    }
}
