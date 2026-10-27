<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant as Code;
use app\common\constant\PaymentResultCode as Result;
use app\common\constant\RefundConstant as State;
use app\common\constant\TradeConstant;
use app\common\util\BusinessNumber;
use app\common\util\Clock;
use app\common\util\SafeLogger;
use app\exception\BusinessException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderOperationLogRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\repository\system\AdminUserRepository;
use app\service\payment\config\PaymentChannelRevisionService;
use app\service\payment\plugin\PaymentPluginFactoryService;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * 退款由原订单插件执行：先持久化唯一请求，再在事务外调用平台，最后记录明确结果。
 * 准备阶段失败记录为未执行并释放额度；进入退款动作后无法确认的结果继续占用额度。
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
        private readonly SafeLogger $logger,
        private readonly AdminUserRepository $admins,
        private readonly PayOrderOperationLogRepository $operations,
    ) {}

    /**
     * 仅接收已认证应用与校验后的请求。原单锁保护幂等和部分退款总额，不包含外部请求。
     * 未指定 refund_no 使用原订单唯一默认请求；多次部分退款必须提供不同的商户退款号。
     */
    public function refund(
        string $appId,
        string $payNo,
        int $amount,
        ?string $requestNo,
    ): RefundOrder {
        return $this->execute(
            $payNo,
            $amount,
            $requestNo === null ? 'default' : 'merchant:' . $requestNo,
            $appId,
        )['refund'];
    }

    /**
     * 后台身份在认领事务内复核；与应用接口共用额度、原单插件和结果处理。
     *
     * @return array{refund:RefundOrder,message:string} 平台说明仅用于本次响应，持久诊断沿用原因码和技术日志。
     */
    public function refundByAdmin(
        string $payNo,
        int $amount,
        string $requestNo,
        AdminActionContext $actor,
    ): array {
        return $this->execute($payNo, $amount, 'admin:' . $requestNo, $actor);
    }

    /**
     * @param string|AdminActionContext $owner 已认证应用编号或服务端管理员上下文。
     * @return array{refund:RefundOrder,message:string}
     */
    private function execute(
        string $payNo,
        int $amount,
        string $key,
        string|AdminActionContext $owner,
    ): array {
        $pay =
            $this->payments->find($payNo) ??
            throw new BusinessException('订单不存在', Code::NOT_FOUND);
        $claim = $this->transactionRetry(function () use ($pay, $amount, $key, $owner): array {
            if ($owner instanceof AdminActionContext) {
                $admin = $this->admins->lock($owner->adminId);
                if (
                    $admin === null ||
                    (int) $admin['status'] !== 1 ||
                    (int) $admin['session_version'] !== $owner->sessionVersion
                ) {
                    throw new BusinessException('管理员会话已失效', Code::UNAUTHENTICATED);
                }
            }
            // 与其他人工动作保持管理员 → 业务单 → 支付单的锁顺序，平台请求不在事务内。
            $biz = $this->business->lock($pay['biz_no']);
            $locked = $this->payments->lock($pay['pay_no']);
            if (
                (is_string($owner) && $biz['app_id'] !== $owner) ||
                $biz['active_pay_no'] !== $locked['pay_no'] ||
                $locked['biz_no'] !== $biz['biz_no']
            ) {
                throw new BusinessException('订单不存在', Code::NOT_FOUND);
            }
            if (
                (int) $biz['status'] !== TradeConstant::ORDER_STATUS_SUCCESS ||
                (int) $locked['status'] !== TradeConstant::ORDER_STATUS_SUCCESS
            ) {
                throw new BusinessException('订单尚未支付成功', Code::CONFLICT);
            }
            $existing = $this->refunds->forRequest($locked['pay_no'], $key);
            if ($existing !== null) {
                if ((int) $existing['refund_amount'] !== $amount) {
                    throw new BusinessException('同一退款号的金额不一致', Code::CONFLICT);
                }
                return ['execute' => false, 'refund' => $existing, 'pay' => $locked];
            }
            $received = $locked->receivedAmount();
            if ($received === null || $received < 1) {
                throw new BusinessException('支付单缺少已确认的实际支付金额', Code::CONFLICT);
            }
            $reservation = $this->refunds->reservation($locked['pay_no']);
            if ($amount < 1 || $reservation['reserved_amount'] + $amount > $received) {
                throw new BusinessException('退款金额超过订单可退金额', Code::CONFLICT);
            }
            if ($owner instanceof AdminActionContext && $reservation['has_unresolved']) {
                throw new BusinessException(
                    '已有退款处理中或结果待核实，请先核对原退款',
                    Code::CONFLICT,
                );
            }
            $number = $this->numbers->insert(
                'R',
                $this->clock->now(),
                fn(string $number) => $this->refunds->create([
                    'refund_no' => $number,
                    'pay_no' => $locked['pay_no'],
                    'request_key' => $key,
                    'refund_amount' => $amount,
                ]),
            );
            if ($owner instanceof AdminActionContext) {
                $this->operations->record(
                    $locked['pay_no'],
                    $owner->adminId,
                    $owner->requestId,
                    $owner->reason,
                    'manual_refund_requested',
                    TradeConstant::ORDER_STATUS_SUCCESS,
                    TradeConstant::ORDER_STATUS_SUCCESS,
                    ['refund_no' => $number, 'refund_amount' => $amount],
                );
            }
            return ['execute' => true, 'refund' => $this->refunds->find($number), 'pay' => $locked];
        });
        $refund = $claim['refund'];
        if (!$claim['execute']) {
            return ['refund' => $refund, 'message' => Result::label($refund['result_code'])];
        }
        $pay = $claim['pay'];
        $status = State::UNKNOWN;
        $channelNumber = null;
        $reason = Result::REFUND_UNKNOWN;
        $fault = null;
        $invoked = false;
        $message = null;
        try {
            // 配置和平台单号取自原订单，当前通道换插件/停用不改变退款目标。
            $snapshot = $this->revisions->forOrder($pay);
            $plugin = $this->plugins->initialize($pay['plugin_code'], $snapshot['configuration']);
            $input = [
                'refund_no' => $refund['refund_no'],
                'pay_no' => $pay['pay_no'],
                'channel_id' => $pay['channel_id'],
                'refund_amount' => $amount,
                'chan_order_no' => $pay['channel_order_no'],
                'chan_trade_no' => $pay['channel_trade_no'],
                'extra' => $pay['ext_json'],
            ];
            // init 只装配参数。只有进入 refund 动作后，异常才可能意味着平台已受理。
            $invoked = true;
            $result = $plugin->refund($input);
            if (
                ($result['refund_no'] ?? null) !== $refund['refund_no'] ||
                ($result['pay_no'] ?? null) !== $pay['pay_no'] ||
                ($result['refund_amount'] ?? null) !== $amount ||
                !array_key_exists('chan_refund_no', $result) ||
                ($result['chan_refund_no'] !== null &&
                    (!is_string($result['chan_refund_no']) ||
                        $result['chan_refund_no'] === '' ||
                        strlen($result['chan_refund_no']) > 128)) ||
                !in_array(
                    $result['status'] ?? null,
                    ['success', 'pending', 'unknown', 'failed'],
                    true,
                )
            ) {
                throw new UnexpectedValueException('插件退款结果与原请求不一致');
            }
            // 同一插件结果一次映射状态和诊断码，避免两处分支维护不同步。
            [$status, $reason] = match ($result['status']) {
                'success' => [State::SUCCESS, Result::REFUND_SUCCESS],
                'failed' => [State::FAILED, Result::REFUND_FAILED],
                'pending' => [State::PROCESSING, Result::REFUND_PENDING],
                'unknown' => [State::UNKNOWN, Result::REFUND_UNKNOWN],
            };
            $channelNumber = $result['chan_refund_no'];
        } catch (UnsupportedPaymentOperationException) {
            $status = State::FAILED;
            $reason = Result::REFUND_UNSUPPORTED;
        } catch (PaymentDefinitiveException | PaymentUncertainException $error) {
            $rejected = $error instanceof PaymentDefinitiveException;
            $status = $rejected ? State::FAILED : State::UNKNOWN;
            $reason = $rejected ? Result::REFUND_REJECTED : Result::REFUND_UNKNOWN;
            $message =
                Result::label($reason) . '：' . mb_strcut($error->getMessage(), 0, 2048, 'UTF-8');
            // 插件/SDK 先脱敏；拒绝和未知都留下有界诊断，日志不改变额度及不重发规则。
            $this->logger->record(
                $rejected ? 'payment.refund_rejected' : 'payment.refund_unknown',
                [
                    'pay_no' => $pay['pay_no'],
                    'refund_no' => $refund['refund_no'],
                    'plugin_code' => $pay['plugin_code'],
                    'reason_code' => $reason,
                    'error_message' => $error->getMessage(),
                ],
                'warning',
            );
        } catch (Throwable $error) {
            // 不把 SDK/程序异常文本存进公开退款信息；持久化分类后仍抛原异常供统一日志处理。
            $reason = Result::REFUND_FAULT;
            $fault = $error;
        }
        if (!$invoked) {
            // 快照读取和初始化失败时尚未执行退款，不占用“结果未知”的待核实额度。
            $status = State::FAILED;
            $reason = Result::REFUND_NOT_EXECUTED;
            $message = null;
        }
        $this->transactionRetry(function () use (
            $pay,
            $refund,
            $status,
            $channelNumber,
            $reason,
        ): void {
            $this->business->lock($pay['biz_no']);
            $this->payments->lock($pay['pay_no']);
            $this->refunds->finish(
                $refund['refund_no'],
                $status,
                $channelNumber,
                $reason,
                $this->clock->now()->format('Y-m-d H:i:s'),
            );
        });
        if ($fault !== null) {
            throw $fault;
        }
        return [
            'refund' =>
                $this->refunds->find($refund['refund_no']) ??
                throw new RuntimeException('退款记录缺失'),
            'message' => $message ?? Result::label($reason),
        ];
    }
}
