<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\constant\PaymentResultCode as Result;
use app\service\payment\notify\NotifyService;
use app\common\constant\NotifyConstant;
use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\service\payment\cashier\PaymentPresentation;
use app\common\util\Clock;
use app\common\base\BaseService;
use app\exception\BusinessException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\ReceiptFlow;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\receipt\ReceiptFlowRepository;
use app\repository\payment\receipt\ReceiptMatchReservationRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayCallbackLogRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\PayOrderOperationLogRepository;
use app\repository\system\AdminUserRepository;
use app\service\payment\receipt\ReceiptEvent;

/**
 * 交易状态推进的统一事务入口；可信确认、业务同步、流水消费和通知责任一起提交。
 * 网络请求必须由调用方在事务之外执行。本类不接收客户端声称的 success 状态。
 */
final class PayOrderLifecycleService extends BaseService
{
    public function __construct(
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderRepository $payOrders,
        private readonly ReceiptScopeRepository $scopes,
        private readonly ReceiptFlowRepository $flows,
        private readonly ReceiptMatchReservationRepository $reservations,
        private readonly NotifyService $notifications,
        private readonly NotifyTaskRepository $tasks,
        private readonly PayCallbackLogRepository $logs,
        private readonly AdminUserRepository $admins,
        private readonly PayOrderOperationLogRepository $operations,
        private readonly Clock $clock,
    ) {
    }

    /**
     * 人工确认与自动确认共用相同业务/通知不变量，但证据来自已复核密码的管理员判断。
     * 不生成虚构流水，只保存管理员核实依据；以后收到同一真实收款时可补齐唯一自动流水。
     *
     * @throws BusinessException 旧会话、证据冲突、金额不足或时间不合理，不能覆盖原成功事实。
     */
    public function confirmManual(string $payNo, AdminActionContext $actor, ManualReceiptEvidence $evidence, \DateTimeImmutable $now, bool $notifyMerchant = true): PayOrder
    {
        $target = $this->payOrders->find($payNo) ?? throw new BusinessException('支付单不存在', ApiResponseConstant::NOT_FOUND);
        $now = $now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        return $this->transaction(function () use ($target, $payNo, $actor, $evidence, $now, $notifyMerchant): PayOrder {
            $this->assertAdmin($actor);
            $biz = $this->bizOrders->lock($target['biz_no']);
            $pay = $this->payOrders->lock($payNo);
            $this->assertOrderLink($biz, $pay);
            $this->scopes->lock($pay['receipt_scope_key']);
            $time = $now->format('Y-m-d H:i:s');
            $proof = $evidence->toArray() + ['notify_merchant' => $notifyMerchant];
            $before = (int) $pay['status'];
            // 此前只有锁定的当前读；范围锁串行化其他确认，首次一致性读取能看到已提交的原交易消费。
            if ($evidence->platformTradeNo !== null
                && $this->payOrders->hasSuccessfulTrade($pay['receipt_scope_key'], $evidence->platformTradeNo, $payNo)) {
                throw new BusinessException('该真实交易号已用于其他成功订单', ApiResponseConstant::CONFLICT);
            }
            if ($evidence->receivedAmount < (int) $biz['order_amount'] || $proof['paid_at'] < $pay['created_at'] || $proof['paid_at'] > $time) {
                throw new BusinessException('核实金额不足或付款时间不在合理范围', ApiResponseConstant::VALIDATION_FAILED);
            }
            if ($pay['channel_trade_no'] !== null && $pay['channel_trade_no'] !== $evidence->platformTradeNo) {
                throw new BusinessException('人工证据与已有真实渠道引用冲突', ApiResponseConstant::CONFLICT);
            }
            if ($before === TradeConstant::ORDER_STATUS_SUCCESS) {
                if ((int) $pay->receivedAmount() !== $evidence->receivedAmount || $pay['paid_at'] !== $proof['paid_at']
                    || $pay['channel_trade_no'] !== $evidence->platformTradeNo) {
                    throw new BusinessException('订单已成功，不能覆盖原收款事实', ApiResponseConstant::CONFLICT);
                }
                // 重复确认只核对原责任，不按本次勾选值改变已提交的通知安排。
                $this->assertNotification($biz, $pay);
                $this->operations->record($payNo, $actor->adminId, $actor->requestId, $actor->reason,
                    'manual_confirmation_replayed', $before, $before, $proof);
                return $pay;
            }
            if (!in_array($before, [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING,
                TradeConstant::ORDER_STATUS_FAILED, TradeConstant::ORDER_STATUS_CLOSED, TradeConstant::ORDER_STATUS_TIMEOUT], true)) {
                throw new \RuntimeException('未知支付状态');
            }
            $changes = ['status' => TradeConstant::ORDER_STATUS_SUCCESS, 'confirmation_source' => TradeConstant::CONFIRMATION_MANUAL,
                'paid_at' => $proof['paid_at'], 'channel_trade_no' => $evidence->platformTradeNo]
                + $this->receiptAmountChanges($pay, $evidence->receivedAmount, $proof['paid_at'],
                    $evidence->platformTradeNo, null);
            $this->payOrders->saveLifecycle($payNo, $before, $changes, $time);
            $this->bizOrders->saveLifecycle($biz['biz_no'], (int) $biz['status'], [
                'status' => TradeConstant::ORDER_STATUS_SUCCESS, 'paid_amount' => (int) $biz['order_amount'], 'paid_at' => $proof['paid_at'],
            ], $time);
            $this->releaseReceipt($pay, $now);
            $confirmedBiz = $this->bizOrders->find($biz['biz_no']) ?? throw new \RuntimeException('确认业务单不可读取');
            $confirmedPay = $this->payOrders->find($payNo) ?? throw new \RuntimeException('确认支付单不可读取');
            $this->notifications->createPaySuccess($confirmedBiz, $confirmedPay, $time, $notifyMerchant);
            $this->operations->record($payNo, $actor->adminId, $actor->requestId, $actor->reason,
                'manual_receipt_confirmed', $before, TradeConstant::ORDER_STATUS_SUCCESS, $proof);
            return $confirmedPay;
        });
    }

    /** 本地关闭和原因审计一起提交；成功不能关闭，原二维码不因本地状态失去收款能力。 */
    public function closeLocally(string $payNo, AdminActionContext $actor, \DateTimeImmutable $now): PayOrder
    {
        $target = $this->payOrders->find($payNo) ?? throw new BusinessException('支付单不存在', ApiResponseConstant::NOT_FOUND);
        $now = $now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        return $this->transaction(function () use ($target, $payNo, $actor, $now): PayOrder {
            $this->assertAdmin($actor);
            $biz = $this->bizOrders->lock($target['biz_no']);
            $pay = $this->payOrders->lock($payNo);
            $this->assertOrderLink($biz, $pay);
            $before = (int) $pay['status'];
            if ($before === TradeConstant::ORDER_STATUS_CLOSED) {
                $this->operations->record($payNo, $actor->adminId, $actor->requestId, $actor->reason,
                    'local_close_replayed', $before, $before, ['close_semantics' => 'local_only']);
                return $pay;
            }
            if (!in_array($before, [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true)) {
                throw new BusinessException('当前订单状态不能本地关闭', ApiResponseConstant::CONFLICT);
            }
            $this->scopes->lock($pay['receipt_scope_key']);
            $time = $now->format('Y-m-d H:i:s');
            $this->payOrders->saveLifecycle($payNo, $before, ['status' => TradeConstant::ORDER_STATUS_CLOSED, 'closed_at' => $time], $time);
            $this->bizOrders->saveLifecycle($biz['biz_no'], (int) $biz['status'], ['status' => TradeConstant::ORDER_STATUS_CLOSED, 'closed_at' => $time], $time);
            $this->releaseReceipt($pay, $now);
            $this->operations->record($payNo, $actor->adminId, $actor->requestId, $actor->reason,
                'local_close', $before, TradeConstant::ORDER_STATUS_CLOSED, ['close_semantics' => 'local_only']);
            return $this->payOrders->find($payNo) ?? throw new \RuntimeException('关闭后的支付单不可读取');
        });
    }

    /** 管理员锁位于业务单之前；无需读取全局 Session，已撤销的上下文在事务内再次拒绝。 */
    private function assertAdmin(AdminActionContext $actor): void
    {
        $admin = $this->admins->lock($actor->adminId);
        if ($admin === null || (int) $admin['status'] !== 1 || (int) $admin['session_version'] !== $actor->sessionVersion) {
            throw new BusinessException('管理员会话已失效', ApiResponseConstant::UNAUTHENTICATED);
        }
    }

    /**
     * 在调用插件前保存执行身份和等待状态；重复请求在租约内复用，不能并发重调 pay。
     * 仅明确无外部写副作用的实现允许到期后重建材料；其他执行即使崩溃也不自动重下单。
     *
     * @param bool $replaySafe 派发服务对静态码本地准备可重放性的判断；已有执行必须与首次记录一致。
     * @param int $leaseSeconds 执行认领期限，秒；不是付款有效期。
     * @return array{execute:bool,token:?string,pay:PayOrder} execute=false 时 token 为 null，不授予调用权。
     */
    public function beginExecution(string $payNo, bool $replaySafe, int $leaseSeconds, \DateTimeImmutable $now, string $requestId): array
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 300) {
            throw new \InvalidArgumentException('支付执行认领期限越界');
        }
        $target = $this->payOrders->find($payNo) ?? throw new \RuntimeException('支付单不存在');
        // 到期处理拥有独立事务；先完成它，再认领，避免嵌套事务和给已到期订单展示付款材料。
        $this->expire($target['biz_no'], $now);
        $now = $now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        return $this->transaction(function () use ($target, $payNo, $replaySafe, $leaseSeconds, $now, $requestId): array {
            $biz = $this->bizOrders->lock($target['biz_no']);
            $pay = $this->payOrders->lock($payNo);
            $this->assertOrderLink($biz, $pay);
            // 前置到期扫描与本事务之间可能再次等锁；调用权必须按取锁后的时间判断。
            $now = max($now, $this->clock->now())->setTimezone(new \DateTimeZone('Asia/Shanghai'));
            if ($this->expireWaiting($biz, $now)) {
                return ['execute' => false, 'token' => null, 'pay' => $this->payOrders->find($payNo)
                    ?? throw new \RuntimeException('到期支付单不可读取')];
            }
            $before = (int) $pay['status'];
            if (!in_array($before, [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true)) {
                return ['execute' => false, 'token' => null, 'pay' => $pay];
            }
            $time = $now->format('Y-m-d H:i:s');
            $extension = $pay['ext_json'] ?? [];
            $execution = $extension['payment_context']['execution'] ?? null;
            if ($execution !== null) {
                if (!is_array($execution) || !is_string($execution['lease_until'] ?? null)
                    || ($execution['replay_safe'] ?? null) !== $replaySafe) {
                    throw new \RuntimeException('原支付执行恢复合同损坏或已改变');
                }
                if (($execution['phase'] ?? null) === 'presented' || !$replaySafe || $execution['lease_until'] > $time) {
                    return ['execute' => false, 'token' => null, 'pay' => $pay];
                }
                if (!in_array($execution['phase'] ?? null, ['claimed', 'uncertain'], true)) {
                    throw new \RuntimeException('未知支付执行阶段');
                }
            } elseif ($pay['request_at'] !== null || $before !== TradeConstant::ORDER_STATUS_CREATED) {
                throw new \RuntimeException('已有支付执行缺少原认领上下文');
            }
            $token = bin2hex(random_bytes(16));
            $extension['payment_context']['execution'] = [
                'token' => $token, 'phase' => 'claimed', 'replay_safe' => $replaySafe,
                'lease_until' => $now->modify('+' . $leaseSeconds . ' seconds')->format('Y-m-d H:i:s'),
            ];
            // 原 request_at 永不因静态展示恢复而移动；可信到账仍按首次执行及原占用窗口核对。
            $this->payOrders->saveLifecycle($payNo, $before, [
                'status' => TradeConstant::ORDER_STATUS_PAYING, 'request_at' => $pay['request_at'] ?? $time,
                'ext_json' => json_encode($extension, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ], $time);
            if ((int) $biz['status'] === TradeConstant::ORDER_STATUS_CREATED) {
                $this->bizOrders->saveLifecycle($biz['biz_no'], (int) $biz['status'], ['status' => TradeConstant::ORDER_STATUS_PAYING], $time);
            }
            $this->logs->recordExecution($payNo, $requestId, $token, Result::EXECUTION_CLAIMED, $before);
            return ['execute' => true, 'token' => $token,
                'pay' => $this->payOrders->find($payNo) ?? throw new \RuntimeException('认领后的支付单不可读取')];
        });
    }

    /**
     * 插件调用结束后的短事务回写，原 token 不符或支付已终态时只记审计，不覆盖新执行或成功。
     * uncertain 保持 PAYING；rejected 仅由派发边界捕获确定拒绝后传入，异常文案不进入快照。
     *
     * @param string $outcome presented/uncertain/rejected 三种已分类的执行结果。
     * @param PaymentPresentation|null $presentation 只有 presented 允许并要求公开材料。
     * @return PayOrder 当前持久状态；不能直接用插件结果构造 HTTP 支付成功。
     */
    public function finishExecution(
        string $payNo,
        string $token,
        string $outcome,
        ?PaymentPresentation $presentation,
        \DateTimeImmutable $now,
        string $requestId,
        ?string $channelOrderNo = null,
    ): PayOrder {
        if (!in_array($outcome, ['presented', 'uncertain', 'rejected'], true)
            || ($outcome === 'presented') !== ($presentation !== null)
            || ($channelOrderNo !== null && ($channelOrderNo === '' || strlen($channelOrderNo) > 128))) {
            throw new \InvalidArgumentException('支付执行回写合同不一致');
        }
        $target = $this->payOrders->find($payNo) ?? throw new \RuntimeException('支付单不存在');
        $now = $now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        // 调用期间可能跨过付款截止点，回写前先结束到期待付，避免再发布过期二维码材料。
        $this->expire($target['biz_no'], $now);
        return $this->transaction(function () use ($target, $payNo, $token, $outcome, $presentation, $now, $requestId, $channelOrderNo): PayOrder {
            $biz = $this->bizOrders->lock($target['biz_no']);
            $pay = $this->payOrders->lock($payNo);
            $this->assertOrderLink($biz, $pay);
            $now = max($now, $this->clock->now())->setTimezone(new \DateTimeZone('Asia/Shanghai'));
            if ($this->expireWaiting($biz, $now)) {
                $pay = $this->payOrders->find($payNo) ?? throw new \RuntimeException('到期支付单不可读取');
            }
            $before = (int) $pay['status'];
            $extension = $pay['ext_json'] ?? [];
            $execution = $extension['payment_context']['execution'] ?? [];
            if (!is_string($execution['token'] ?? null) || !hash_equals($execution['token'], $token)) {
                $this->logs->recordExecution($payNo, $requestId, $token, Result::STALE_EXECUTION_RESULT, $before);
                return $pay;
            }
            if ($before !== TradeConstant::ORDER_STATUS_PAYING || ($execution['phase'] ?? null) !== 'claimed') {
                $this->logs->recordExecution($payNo, $requestId, $token, Result::EXECUTION_RESULT_NOT_APPLICABLE, $before);
                return $pay;
            }
            $time = $now->format('Y-m-d H:i:s');
            if (!is_string($execution['lease_until'] ?? null) || $execution['lease_until'] <= $time) {
                $this->logs->recordExecution($payNo, $requestId, $token, Result::EXPIRED_EXECUTION_RESULT, $before);
                return $pay;
            }
            $extension['payment_context']['execution']['phase'] = $outcome;
            if ($presentation !== null) {
                $extension['presentation'] = $presentation->toArray();
            }
            $changes = ['ext_json' => json_encode($extension, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
            if ($channelOrderNo !== null) {
                if ($pay['channel_order_no'] !== null && $pay['channel_order_no'] !== $channelOrderNo) {
                    throw new \UnexpectedValueException('插件重放返回了不同的平台订单');
                }
                $changes['channel_order_no'] = $channelOrderNo;
            }
            if ($outcome === 'rejected') {
                $this->scopes->lock($pay['receipt_scope_key']);
                $changes += ['status' => TradeConstant::ORDER_STATUS_FAILED, 'failed_at' => $time];
                $this->bizOrders->saveLifecycle($biz['biz_no'], (int) $biz['status'],
                    ['status' => TradeConstant::ORDER_STATUS_FAILED, 'failed_at' => $time], $time);
                $this->releaseReceipt($pay, $now);
            }
            $this->payOrders->saveLifecycle($payNo, $before, $changes, $time);
            $this->logs->recordExecution($payNo, $requestId, $token, match ($outcome) { 'presented' => Result::EXECUTION_PRESENTED, 'uncertain' => Result::EXECUTION_UNCERTAIN, 'rejected' => Result::EXECUTION_REJECTED }, $before);
            return $this->payOrders->find($payNo) ?? throw new \RuntimeException('执行回写后的支付单不可读取');
        });
    }

    /**
     * 插件已逐条定位订单；按 Biz → Pay → Scope → Flow 锁序复核后才写最终流水。
     * 流水、订单成功和通知责任同事务提交。无匹配或证据冲突不保存流水，也没有后台补匹配队列。
     * @return array{flow_id:?string,pay_no:?string,matched:bool,reason:string}
     */
    public function confirmReceipt(ReceiptEvent $event, \DateTimeImmutable $receivedAt, string $requestId): array
    {
        $ignore = static fn (string $reason): array => ['flow_id'=>null,'pay_no'=>null,'matched'=>false,'reason'=>$reason];
        if ($event->locatedPayNo === null) { return $ignore('no_candidate'); }
        $target = $this->payOrders->find($event->locatedPayNo) ?? throw new \RuntimeException('插件所选支付单不存在');
        $received = $receivedAt->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        // 来源认证由入口和插件完成；最终确认仍必须具有合理时间及事务内的金额、归属证据。
        if ($event->paidAt === null || $event->paymentTime() > $received) { return $ignore('insufficient_receipt_evidence'); }
        return $this->transactionRetry(function () use ($event, $received, $receivedAt, $requestId, $target, $ignore): array {
            $biz = $this->bizOrders->lock($target['biz_no']);
            $pay = $this->payOrders->lock($target['pay_no']);
            $scope = $this->scopes->lock($pay['receipt_scope_key']);
            $this->assertOrderLink($biz, $pay);
            $payNo = $pay['pay_no'];
            $time = $this->clock->now()->format('Y-m-d H:i:s');
            if ($pay['receipt_scope_key'] !== $event->scopeKey || $scope['pay_type_id'] !== $pay['pay_type_id']
                || ($pay['ext_json']['payment_context']['pay_type'] ?? null) !== $event->payType
                || $pay['plugin_code'] !== $event->pluginCode) { return $ignore('receipt_context_mismatch'); }
            if ($event->platformTradeNo !== null) {
                $consumed = $this->flows->lockByTrade($event->pluginCode, $event->platformTradeNo);
                if ($consumed !== null && $consumed['pay_no'] !== $payNo) { return $ignore('platform_trade_already_consumed'); }
            }
            $existing = $this->flows->lockByEvent($event->scopeKey, $event->pluginCode, $event->eventKey);
            if ($existing !== null) {
                if (!hash_equals($existing['payload_digest'], $event->digest($existing['account_key']))) {
                    return $ignore('event_content_conflict');
                }
                if ($existing['pay_no'] !== $payNo) { return $ignore('platform_trade_already_consumed'); }
                if ((int) $pay['status'] !== TradeConstant::ORDER_STATUS_SUCCESS || $pay['receipt_flow_id'] !== $existing['id']) {
                    throw new \RuntimeException('流水与成功订单关联不一致');
                }
                $this->assertNotification($biz, $pay);
                return $this->recordReceipt($existing, $requestId, Result::CONFIRMATION_REPLAYED);
            }
            if ($event->platformTradeNo !== null && $this->payOrders->hasSuccessfulTrade($event->scopeKey, $event->platformTradeNo, $payNo)) {
                return $ignore('platform_trade_already_consumed');
            }
            $before = (int) $pay['status'];
            if ($before === TradeConstant::ORDER_STATUS_SUCCESS) {
                // 不同消息键报告同一收款时沿用原流水，不新增重复记录，也不再次生成通知。
                if ($pay['channel_trade_no'] !== $event->platformTradeNo || $event->platformTradeNo === null
                    || $pay->receivedAmount() !== $event->amount) { return $ignore('additional_receipt'); }
                $this->assertNotification($biz, $pay);
                if ($pay['receipt_flow_id'] !== null) {
                    $primary = $this->flows->lock($pay['receipt_flow_id']);
                    if ($primary['pay_no'] !== $payNo || $primary['receipt_scope_key'] !== $event->scopeKey) {
                        throw new \RuntimeException('原主流水关联不一致');
                    }
                    if ((int) $primary['amount'] !== $event->amount || $primary['paid_at'] !== $event->paymentTime()) {
                        return $ignore('event_content_conflict');
                    }
                    return $this->recordReceipt($primary, $requestId, Result::CONFIRMATION_REPLAYED);
                }
                if ($pay['confirmation_source'] !== TradeConstant::CONFIRMATION_MANUAL) { throw new \RuntimeException('自动成功缺少主流水'); }
                $reason = Result::MANUAL_EVIDENCE_SUPPLEMENTED;
            } else {
                $reservation = $this->reservations->findForPay($payNo);
                if ($reservation === null || $reservation['receipt_scope_key'] !== $event->scopeKey
                    || ($pay['ext_json']['personal_receipt']['account_key'] ?? null) !== $event->accountKey) {
                    return $ignore('receipt_context_mismatch');
                }
                if ($event->amount !== $pay->receiptAmount() || (int) $reservation['receipt_amount'] !== $event->amount
                    || ($reservation['match_mode'] === 'remark' && $event->remark !== $reservation['match_value'])
                    || $event->amount < $pay->originalAmount() || $event->paymentTime() < $reservation['valid_from']
                    || $event->paymentTime() >= $reservation['expire_at']) { return $ignore('receipt_value_mismatch'); }
                if (!in_array($before, [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING,
                    TradeConstant::ORDER_STATUS_CLOSED, TradeConstant::ORDER_STATUS_TIMEOUT], true)) { return $ignore('receipt_after_definitive_failure'); }
                if ($pay['request_at'] === null || $event->paymentTime() < $pay['request_at']) { return $ignore('execution_not_started'); }
                if ($pay['channel_trade_no'] !== null && $pay['channel_trade_no'] !== $event->platformTradeNo) { return $ignore('channel_reference_conflict'); }
                $reason = in_array($before, [TradeConstant::ORDER_STATUS_CLOSED, TradeConstant::ORDER_STATUS_TIMEOUT], true)
                    || $received > $pay['expire_at'] ? Result::LATE_DELIVERY_CONFIRMED : Result::RECEIPT_CONFIRMED;
            }
            // 至此已经确定最终绑定；不先写候选行、待核对行或其他账户的无关收款。
            $flowId = $this->flows->create(['receipt_scope_key'=>$event->scopeKey,'account_key'=>$event->accountKey,
                'plugin_code'=>$event->pluginCode,'event_key'=>$event->eventKey,
                'payload_digest'=>$event->digest(),'platform_trade_no'=>$event->platformTradeNo,'amount'=>$event->amount,
                'remark'=>$event->remark,'paid_at'=>$event->paymentTime(),'received_at'=>$received,'pay_no'=>$payNo,
                'reason_code'=>$reason,'evidence_json'=>json_encode(['version'=>4,'pay_type'=>$event->payType,
                    'config_version'=>$event->configVersion,
                    'source_payload_digest'=>$event->sourcePayloadDigest,'source_reference'=>$event->sourceReference()], JSON_THROW_ON_ERROR)]);
            if ($before === TradeConstant::ORDER_STATUS_SUCCESS) {
                $this->payOrders->saveLifecycle($payNo, $before, ['receipt_flow_id'=>$flowId], $time);
            } else {
                $this->payOrders->saveLifecycle($payNo, $before, ['status'=>TradeConstant::ORDER_STATUS_SUCCESS,
                    'receipt_flow_id'=>$flowId,'confirmation_source'=>TradeConstant::CONFIRMATION_RECEIPT,'channel_trade_no'=>$event->platformTradeNo,
                    'paid_at'=>$event->paymentTime()] + $this->receiptAmountChanges($pay, $event->amount,
                        $event->paymentTime(), $event->platformTradeNo, $event->eventKey), $time);
                $this->bizOrders->saveLifecycle($biz['biz_no'], (int) $biz['status'], ['status'=>TradeConstant::ORDER_STATUS_SUCCESS,
                    'paid_amount'=>(int) $biz['order_amount'],'paid_at'=>$event->paymentTime()], $time);
                $this->releaseReceipt($pay, $receivedAt);
                $confirmedBiz = $this->bizOrders->find($biz['biz_no']) ?? throw new \RuntimeException('成功业务单不可读取');
                $confirmedPay = $this->payOrders->find($payNo) ?? throw new \RuntimeException('成功支付单不可读取');
                $this->notifications->createPaySuccess($confirmedBiz, $confirmedPay, $time);
            }
            return $this->recordReceipt($this->flows->lock($flowId), $requestId, $reason, $before);
        });
    }

    /**
     * 到期扫描只结束本地等待；成功及其他终态不降级，未建立支付单的业务需求也能到期。
     * 仍保留原 paid_at 匹配窗口和占用历史，可信窗口内的迟到事件可随后补正确认。
     *
     * @return bool 本次是否实际推进了 TIMEOUT。
     */
    public function expire(string $bizNo, \DateTimeImmutable $now): bool
    {
        $now = $now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        return $this->transaction(function () use ($bizNo, $now): bool {
            $biz = $this->bizOrders->lock($bizNo);
            return $this->expireWaiting($biz, max($now, $this->clock->now())->setTimezone(new \DateTimeZone('Asia/Shanghai')));
        });
    }

    /**
     * 调用方已在本事务锁定 BizOrder；认领/回写和定时扫描共用到期转换，不在事务中嵌套 expire。
     * 已持有 PayOrder 时可重入当前读，随后仍按 Scope → Reservation 方向取锁。
     */
    private function expireWaiting(BizOrder $biz, \DateTimeImmutable $now): bool
    {
        $time = $now->format('Y-m-d H:i:s');
        if (!in_array((int) $biz['status'], [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true)
            || $biz['expire_at'] > $time) {
            return false;
        }
        if ($biz['active_pay_no'] !== null) {
            $pay = $this->payOrders->lock($biz['active_pay_no']);
            $this->assertOrderLink($biz, $pay);
            $this->scopes->lock($pay['receipt_scope_key']);
            $this->payOrders->saveLifecycle($pay['pay_no'], (int) $pay['status'],
                ['status' => TradeConstant::ORDER_STATUS_TIMEOUT, 'timeout_at' => $time], $time);
            $this->releaseReceipt($pay, $now);
        }
        $this->bizOrders->saveLifecycle($biz['biz_no'], (int) $biz['status'],
            ['status' => TradeConstant::ORDER_STATUS_TIMEOUT, 'timeout_at' => $time], $time);
        return true;
    }

    /**
     * 服务在确认事务内，用实际绑定流水或人工证据一起定稿金额、时间及事件身份。
     * 插件此前可能保存过另一条并发通知，不能将其预处理信息留在本次成功快照中。
     *
     * @param int $received 实际到账金额，整数分。
     * @param string $paidAt 本次确认的付款时间，上海时区。
     * @param string|null $tradeNo 本次确认的真实渠道引用；人工未提供时保持 null。
     * @param string|null $eventKey 本次实际关联的流水身份；人工无流水时移除插件预处理身份。
     * @return array{pay_amount:int,ext_json:string}
     */
    private function receiptAmountChanges(PayOrder $pay, int $received, string $paidAt, ?string $tradeNo, ?string $eventKey): array
    {
        $extension = $pay['ext_json'] ?? [];
        $extension['personal_receipt']['original_amount'] = $pay->originalAmount();
        $extension['personal_receipt']['receipt_amount'] = $pay->receiptAmount();
        $extension['personal_receipt']['notified_amount'] = $received;
        $extension['personal_receipt']['notified_at'] = $paidAt;
        $extension['personal_receipt']['channel_trade_no'] = $tradeNo;
        if ($eventKey === null) {
            unset($extension['personal_receipt']['event_key']);
        } else {
            $extension['personal_receipt']['event_key'] = $eventKey;
        }
        return ['pay_amount' => $pay->originalAmount(),
            'ext_json' => json_encode($extension, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }

    /** 统一关联不变量，不能仅凭 pay_no 属于某张表就推进另一个业务需求。 */
    private function assertOrderLink(BizOrder $biz, PayOrder $pay): void
    {
        if ($pay['biz_no'] !== $biz['biz_no'] || $biz['active_pay_no'] !== $pay['pay_no']
            || (int) $biz['attempt_count'] !== 1 || (int) $biz['order_amount'] !== $pay->originalAmount()
            || (int) $biz['status'] !== (int) $pay['status']) {
            throw new \RuntimeException('业务单与唯一支付尝试不一致');
        }
    }

    /** 幂等不等于忽略缺失责任；旧成功必须已经具有对应的原通知，不能现场换密钥重造。 */
    private function assertNotification(BizOrder $biz, PayOrder $pay): void
    {
        $task = $this->tasks->lockByEvent(NotifyConstant::EVENT_PAY_SUCCESS, $pay['pay_no']);
        if ($task === null || $task['biz_no'] !== $biz['biz_no'] || $task['pay_no'] !== $pay['pay_no']
            || $task['credential_version_id'] !== $biz['credential_version_id']) {
            throw new \RuntimeException('已成功订单的原通知责任缺失或不一致');
        }
    }

    /** V2 收款识别在终态释放；历史记录继续支撑重复流水核对，不再延长金额保护期。 */
    private function releaseReceipt(PayOrder $pay, \DateTimeImmutable $now): void
    {
        $this->reservations->releaseForPay($pay['pay_no'], $now->format('Y-m-d H:i:s'));
    }

    /** 最终流水的处理历史与成功事务提交；重复回调只追加历史，不新增流水。 */
    private function recordReceipt(ReceiptFlow $flow, string $requestId, string $reason, ?int $before = null): array
    {
        $summary = ['flow_id'=>$flow['id']];
        if ($before !== null) { $summary['before_status'] = $before; }
        $this->logs->record($flow['pay_no'], $requestId, $flow['event_key'], $flow['payload_digest'], $reason, $summary);
        return ['flow_id'=>$flow['id'],'pay_no'=>$flow['pay_no'],'matched'=>true,'reason'=>$reason];
    }
}
