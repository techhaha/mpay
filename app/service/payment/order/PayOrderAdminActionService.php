<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\TradeConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderOperationLogRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\system\AdminOperationLogRepository;
use app\repository\system\AdminUserRepository;
use app\service\payment\notify\NotificationPolicy;
use app\service\system\access\AdminAuthService;
use DateTimeImmutable;

/** 后台人工动作门面，执行身份/改密版本核验及确认收款前的密码复核，不依赖页面按钮权限。 */
final class PayOrderAdminActionService extends BaseService
{
    public function __construct(
        private readonly AdminUserRepository $admins,
        private readonly PayOrderLifecycleService $lifecycle,
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderRepository $payOrders,
        private readonly NotifyTaskRepository $tasks,
        private readonly PayOrderOperationLogRepository $operations,
        private readonly AdminOperationLogRepository $adminOperations,
        private readonly AdminAuthService $authentication,
        private readonly NotificationPolicy $notificationPolicy,
        private readonly Clock $clock,
        private readonly RefundService $refunds,
    ) {}

    /**
     * 人工确认必须再次验证当前密码；原密码不持久化、不写审计，生命周期只保存已核实的证据。
     * 生命周期仍在锁内核对同一 session_version，防止密码验证后管理员已被停用/改密。
     */
    public function confirm(
        string $payNo,
        AdminActionContext $actor,
        ManualReceiptEvidence $evidence,
        string $currentPassword,
        DateTimeImmutable $now,
        bool $notifyMerchant = true,
    ): PayOrder {
        $this->authentication->verifyPassword(
            $actor->adminId,
            $actor->sessionVersion,
            $actor->requestId,
            $currentPassword,
        );
        return $this->lifecycle->confirmManual($payNo, $actor, $evidence, $now, $notifyMerchant);
    }

    /** 本地关闭要求有效身份和非空原因；不能向用户宣称平台二维码已撤销。 */
    public function close(string $payNo, AdminActionContext $actor): PayOrder
    {
        // 生命周期在关闭事务内核验管理员，取得必要锁后读取本次关闭时间。
        return $this->lifecycle->closeLocally($payNo, $actor);
    }

    /**
     * 密码复核后交给退款服务认领；不能用外层事务包住平台退款请求。
     *
     * @return array{refund:RefundOrder,message:string}
     */
    public function refund(
        string $payNo,
        int $amount,
        string $requestNo,
        AdminActionContext $actor,
        string $currentPassword,
    ): array {
        $this->authentication->verifyPassword(
            $actor->adminId,
            $actor->sessionVersion,
            $actor->requestId,
            $currentPassword,
        );
        return $this->refunds->refundByAdmin($payNo, $amount, $requestNo, $actor);
    }

    /**
     * 业务单尚无尝试也可停止等待；存在支付单时复用完整生命周期，不能为关闭创建一个虚构支付尝试。
     * 从读取到加锁期间若生成了尝试，返回冲突让页面刷新；不在已开启事务里嵌套调用生命周期事务。
     * 无支付单时，在管理员和业务单锁内读取关闭时间；重复关闭保留原时间。
     */
    public function closeBusiness(string $bizNo, AdminActionContext $actor): void
    {
        $observed =
            $this->bizOrders->find($bizNo) ??
            throw new BusinessException('业务订单不存在', ApiResponseConstant::NOT_FOUND);
        if ($observed['active_pay_no'] !== null) {
            $this->close($observed['active_pay_no'], $actor);
            return;
        }
        $this->transaction(function () use ($bizNo, $actor): void {
            $admin = $this->admins->lock($actor->adminId);
            if (
                $admin === null ||
                (int) $admin['status'] !== 1 ||
                (int) $admin['session_version'] !== $actor->sessionVersion
            ) {
                throw new BusinessException(
                    '管理员会话已失效',
                    ApiResponseConstant::UNAUTHENTICATED,
                );
            }
            $biz = $this->bizOrders->lock($bizNo);
            $before = (int) $biz['status'];
            if (
                $biz['active_pay_no'] !== null ||
                !in_array(
                    $before,
                    [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_CLOSED],
                    true,
                )
            ) {
                throw new BusinessException(
                    '订单状态已经变化，请刷新后核对',
                    ApiResponseConstant::CONFLICT,
                );
            }
            if ($before !== TradeConstant::ORDER_STATUS_CLOSED) {
                $time = $this->clock->now()->format('Y-m-d H:i:s');
                $this->bizOrders->saveLifecycle(
                    $bizNo,
                    $before,
                    ['status' => TradeConstant::ORDER_STATUS_CLOSED, 'closed_at' => $time],
                    $time,
                );
            }
            $this->adminOperations->record([
                'admin_user_id' => $actor->adminId,
                'request_id' => $actor->requestId,
                'action' => 'biz.local_close',
                'target_type' => 'biz_order',
                'target_id' => $bizNo,
                'result_code' =>
                    $before === TradeConstant::ORDER_STATUS_CLOSED ? 'unchanged' : 'closed',
                'client_ip' => null,
                'summary_json' => json_encode(
                    [
                        'reason' => $actor->reason,
                        'before_status' => $before,
                        'after_status' => TradeConstant::ORDER_STATUS_CLOSED,
                        'close_semantics' => 'local_only',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ]);
        });
    }

    /**
     * 解除暂缓、提前待发送任务，或为结束任务授权一次补发；Web 仅安排任务，不发网络请求。
     * expectedAttempts 配合任务锁防重复授权；发送中的任务和损坏隔离仍拒绝操作，原 ACK 永久保留。
     *
     * @return array{notify_no:string,attempt_no:int,scheduled:bool} scheduled 不代表业务网站已经收到通知。
     */
    public function retryNotification(
        string $payNo,
        int $expectedAttempts,
        AdminActionContext $actor,
        DateTimeImmutable $now,
    ): array {
        $target =
            $this->payOrders->find($payNo) ??
            throw new BusinessException('支付单不存在', ApiResponseConstant::NOT_FOUND);
        return $this->transaction(function () use (
            $target,
            $payNo,
            $expectedAttempts,
            $actor,
            $now,
        ): array {
            $admin = $this->admins->lock($actor->adminId);
            if (
                $admin === null ||
                (int) $admin['status'] !== 1 ||
                (int) $admin['session_version'] !== $actor->sessionVersion
            ) {
                throw new BusinessException(
                    '管理员会话已失效',
                    ApiResponseConstant::UNAUTHENTICATED,
                );
            }
            // 与成功确认遵循同一方向：管理员 → 业务单 → 支付单 → 原通知；工作者不得反向取得交易锁。
            $biz = $this->bizOrders->lock($target['biz_no']);
            $pay = $this->payOrders->lock($payNo);
            if (
                $pay['biz_no'] !== $biz['biz_no'] ||
                $biz['active_pay_no'] !== $payNo ||
                (int) $biz['status'] !== TradeConstant::ORDER_STATUS_SUCCESS ||
                (int) $pay['status'] !== TradeConstant::ORDER_STATUS_SUCCESS
            ) {
                throw new BusinessException(
                    '只有已确认成功的原支付单才能重新通知',
                    ApiResponseConstant::CONFLICT,
                );
            }
            $task = $this->tasks->lockByEvent(NotifyConstant::EVENT_PAY_SUCCESS, $payNo);
            if (
                $task === null ||
                $task['biz_no'] !== $biz['biz_no'] ||
                $task['pay_no'] !== $payNo
            ) {
                throw new \RuntimeException('成功订单缺少一致的通知责任');
            }
            $used = (int) $task['retry_count'];
            if ($used !== $expectedAttempts || $used >= 4294967295) {
                throw new BusinessException(
                    '通知尝试已变化，请刷新后核对',
                    ApiResponseConstant::CONFLICT,
                );
            }
            $state = (int) $task['status'];
            $replayed =
                $state === NotifyConstant::STATUS_PENDING &&
                (int) $task['manual_attempt_no'] === $used + 1;
            if (!$replayed) {
                if (
                    !in_array(
                        $state,
                        [
                            NotifyConstant::STATUS_PENDING,
                            NotifyConstant::STATUS_HELD,
                            NotifyConstant::STATUS_SUCCESS,
                            NotifyConstant::STATUS_EXHAUSTED,
                        ],
                        true,
                    ) ||
                    $task['manual_attempt_no'] !== null ||
                    $task['claim_token'] !== null ||
                    $task['lease_until'] !== null ||
                    str_starts_with((string) $task['last_response'], 'quarantined_')
                ) {
                    throw new BusinessException(
                        '通知正在发送或记录需要核对，请刷新后再操作',
                        ApiResponseConstant::CONFLICT,
                    );
                }
                if (
                    $state === NotifyConstant::STATUS_HELD &&
                    ($used !== 0 || $task['first_ack_at'] !== null || $task['released_at'] !== null)
                ) {
                    throw new \RuntimeException('暂缓通知存在不一致的发送记录');
                }
                $start = new DateTimeImmutable($task['released_at'] ?? $task['created_at']);
                // 首次解除暂缓开启正常自动重试；已结束的补发只授权一次。提前原计划不重置预算。
                $single =
                    in_array(
                        $state,
                        [NotifyConstant::STATUS_SUCCESS, NotifyConstant::STATUS_EXHAUSTED],
                        true,
                    ) ||
                    ($state === NotifyConstant::STATUS_PENDING &&
                        !$this->notificationPolicy->canAttempt($start, $used, $now));
                $this->tasks->scheduleDelivery(
                    $task,
                    $now->format('Y-m-d H:i:s'),
                    $single,
                    $state === NotifyConstant::STATUS_HELD,
                );
            }
            $this->operations->record(
                $payNo,
                $actor->adminId,
                $actor->requestId,
                $actor->reason,
                $replayed ? 'manual_notify_replayed' : 'manual_notify_scheduled',
                TradeConstant::ORDER_STATUS_SUCCESS,
                TradeConstant::ORDER_STATUS_SUCCESS,
                [
                    'notify_no' => $task['notify_no'],
                    'attempt_no' => $used + 1,
                    'previous_notify_status' => $state,
                ],
            );
            return [
                'notify_no' => $task['notify_no'],
                'attempt_no' => $used + 1,
                'scheduled' => true,
            ];
        });
    }
}
