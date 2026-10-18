<?php
declare(strict_types=1);

namespace app\repository\payment\notify;

use app\common\base\BaseRepository;
use app\common\constant\NotifyConstant;
use app\model\payment\NotifyTask;

/**
 * 持久化业务通知责任及其冻结内容；不派发 HTTP、不推进支付状态、不实现通用任务队列。
 *
 * @extends BaseRepository<NotifyTask>
 */
final class NotifyTaskRepository extends BaseRepository
{
    public function __construct(NotifyTask $model)
    {
        parent::__construct($model);
    }

    /**
     * 生命周期在成功事务内调用；本仓储不决定是否应确认成功。
     * PAY_SUCCESS 的 ref_no 必须为 pay_no，notify_data 保存冻结的业务通知内容。
     * 调用服务须已锁业务单并核验首次成功，仓储不会补做这项业务判断。
     *
     * @param array{notify_no:string, event_type:string, ref_no:string, biz_no:string, pay_no:string,
     *     notify_url:string, notify_data:string, credential_version_id:string, status?:int, next_retry_at?:string|null} $data
     * @throws \think\db\exception\PDOException 同一事件或同一业务成功的通知责任重复。
     */
    public function create(array $data): string
    {
        return $this->insert($data, [
            'created_at',
            'notify_no',
            'event_type',
            'ref_no',
            'biz_no',
            'pay_no',
            'notify_url',
            'notify_data',
            'credential_version_id',
            'status',
            'next_retry_at',
        ]);
    }

    /** 按稳定的业务事件身份查找原通知任务，供服务处理重复责任；不是按发送次数查找。 */
    public function findByEvent(string $eventType, string $refNo): ?NotifyTask
    {
        return $this->model->where('event_type', $eventType)->where('ref_no', $refNo)->find();
    }

    /** 按唯一事件索引读取首次 ACK，不加载通知正文、商户响应或尝试历史。 */
    public function cashierConfirmation(string $eventType, string $payNo): ?NotifyTask
    {
        return $this->model
            ->where('event_type', $eventType)
            ->where('ref_no', $payNo)
            ->field('biz_no,pay_no,status,first_ack_at')
            ->find();
    }

    public function find(string $notifyNo): ?NotifyTask
    {
        return $this->model->where('notify_no', $notifyNo)->find();
    }

    /**
     * @param array{from?:string,until?:string,app_id?:string,notify_no?:string,pay_no?:string,biz_no?:string,status?:int|'acknowledged'} $filters 日期缺省时查询全部；acknowledged 按首次有效应答筛选，不受后来补发结果影响。
     * @return array{items:list<NotifyTask>,total:int} 尝试次数不是任务条数。
     */
    public function adminPage(array $filters, int $page, int $limit): array
    {
        $query = $this->model->alias('n')->field('n.*');
        if (isset($filters['from'], $filters['until'])) {
            $query
                ->where('n.created_at', '>=', $filters['from'])
                ->where('n.created_at', '<', $filters['until']);
        }
        if (isset($filters['app_id'])) {
            $query
                ->join('ma_biz_order b', 'b.biz_no=n.biz_no')
                ->where('b.app_id', $filters['app_id']);
        }
        foreach (['notify_no', 'pay_no', 'biz_no', 'status'] as $field) {
            if (isset($filters[$field])) {
                if ($field === 'status' && $filters[$field] === 'acknowledged') {
                    $query->whereNotNull('n.first_ack_at');
                } else {
                    $query->where('n.' . $field, $filters[$field]);
                }
            }
        }
        return [
            'total' => (int) (clone $query)->count(),
            'items' => $query->order('n.id', 'desc')->page($page, $limit)->select()->all(),
        ];
    }

    /**
     * 批量读取原成功责任，避免列表逐行查库。
     * @param list<string> $payNos
     * @return array<string,NotifyTask>
     */
    public function forPayOrders(array $payNos): array
    {
        if ($payNos === []) {
            return [];
        }
        $result = [];
        foreach (
            $this->model
                ->where('event_type', NotifyConstant::EVENT_PAY_SUCCESS)
                ->whereIn('pay_no', $payNos)
                ->select()
            as $task
        ) {
            $result[$task['pay_no']] = $task;
        }
        return $result;
    }

    /** 成功幂等分支的当前读；调用方已锁业务/支付，不允许通知工作者反向取这些交易锁。 */
    public function lockByEvent(string $eventType, string $refNo): ?NotifyTask
    {
        $this->requireTransaction();
        return $this->model
            ->where('event_type', $eventType)
            ->where('ref_no', $refNo)
            ->lock(true)
            ->find();
    }

    /**
     * 仅取小批候选号，不持锁；实际资格在服务的逐条短事务内复核，支持 MySQL 5.7。
     * 过期租约先恢复，待发任务按到期时间公平扫描，不使用不受支持的 SKIP LOCKED。
     *
     * @return list<string>
     */
    public function dueNumbers(string $now, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('通知扫描批次超出范围');
        }
        $expired = $this->model
            ->where('status', NotifyConstant::STATUS_SENDING)
            ->where(function (\think\db\Query $query) use ($now): void {
                $query->where('lease_until', '<=', $now)->whereNull('lease_until', 'OR');
            })
            ->order('lease_until,id')
            ->limit($limit)
            ->column('notify_no');
        // 过期任务已占满候选上限，待发查询的结果不会进入本批；认领仍逐条锁内复核。
        if (count($expired) === $limit) {
            return $expired;
        }
        $pending = $this->model
            ->where('status', NotifyConstant::STATUS_PENDING)
            ->where(function (\think\db\Query $query) use ($now): void {
                $query->where('next_retry_at', '<=', $now)->whereNull('next_retry_at', 'OR');
            })
            ->order('next_retry_at,id')
            ->limit($limit)
            ->column('notify_no');
        return array_slice(array_values(array_unique([...$expired, ...$pending])), 0, $limit);
    }

    /** 通知工作者只锁通知任务和对应尝试，不反向取得业务单或支付单锁。 */
    public function lock(string $notifyNo): ?NotifyTask
    {
        $this->requireTransaction();
        return $this->model->where('notify_no', $notifyNo)->lock(true)->find();
    }

    /**
     * 持久化服务已经决定的调度方式；保留原签名、创建时间、计数和首次应答。
     * release 仅供首次解除暂缓，singleAttempt 仅授权下一次；本方法不选择业务策略。
     */
    public function scheduleDelivery(
        NotifyTask $task,
        string $now,
        bool $singleAttempt,
        bool $release,
    ): void {
        $this->requireTransaction();
        $values = [
            'status' => NotifyConstant::STATUS_PENDING,
            'next_retry_at' => $now,
            'manual_attempt_no' => $singleAttempt ? (int) $task['retry_count'] + 1 : null,
            'updated_at' => $now,
        ];
        if ($release) {
            $values['released_at'] = $now;
        }
        $changed = $this->model
            ->where('notify_no', $task['notify_no'])
            ->where('status', (int) $task['status'])
            ->where('retry_count', (int) $task['retry_count'])
            ->whereNull('manual_attempt_no')
            ->whereNull('claim_token')
            ->whereNull('lease_until')
            ->update($values);
        // 同秒重复提前到期任务可能不改变值，原锁内版本一致即可；不重复创建发送尝试。
        if (
            $changed === 0 &&
            ((int) $task['status'] !== NotifyConstant::STATUS_PENDING ||
                $singleAttempt ||
                $release ||
                $task['next_retry_at'] !== $now)
        ) {
            throw new \RuntimeException('已锁定通知的人工调度条件发生变化');
        }
    }

    /** 调用方已锁定并核对任务到期；次数在发 HTTP 前持久化，崩溃不能免费重置尝试额度。 */
    public function claim(
        NotifyTask $task,
        string $token,
        int $attempt,
        string $now,
        string $until,
    ): void {
        $this->requireTransaction();
        $changed = $this->model
            ->where('notify_no', $task['notify_no'])
            ->where('status', (int) $task['status'])
            ->where('retry_count', (int) $task['retry_count'])
            ->update([
                'status' => NotifyConstant::STATUS_SENDING,
                'claim_token' => $token,
                'lease_until' => $until,
                'retry_count' => $attempt,
                'last_notify_at' => $now,
                'next_retry_at' => null,
                'updated_at' => $now,
            ]);
        if ($changed !== 1) {
            throw new \RuntimeException('通知认领条件发生变化');
        }
    }

    /**
     * 结果只能由仍有效的原 token 回写；更新条件同时保护状态、尝试序号和租约。
     *
     * @param string $reason 固定脱敏结果码，禁止传入响应正文或异常文本。
     * @return bool false 表示租约或任务已变更，调用方不能把该响应报告为已入库 ACK。
     */
    public function finish(
        string $notifyNo,
        string $token,
        int $attempt,
        int $status,
        ?string $next,
        string $reason,
        string $now,
        ?string $firstAckAt,
    ): bool {
        $this->requireTransaction();
        if (
            !in_array(
                $status,
                [
                    NotifyConstant::STATUS_PENDING,
                    NotifyConstant::STATUS_SUCCESS,
                    NotifyConstant::STATUS_EXHAUSTED,
                ],
                true,
            ) ||
            !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason)
        ) {
            throw new \InvalidArgumentException('通知回写状态或结果码无效');
        }
        return $this->model
            ->where('notify_no', $notifyNo)
            ->where('status', NotifyConstant::STATUS_SENDING)
            ->where('claim_token', $token)
            ->where('retry_count', $attempt)
            ->where('lease_until', '>', $now)
            ->update([
                'status' => $status,
                'next_retry_at' => $next,
                'last_response' => $reason,
                'first_ack_at' => $firstAckAt,
                'manual_attempt_no' => null,
                'claim_token' => null,
                'lease_until' => null,
                'updated_at' => $now,
            ]) === 1;
    }

    /** 已锁定且过期的原租约按失败退避恢复，防止连续崩溃绕过正常间隔；不立即重发。 */
    public function recoverExpired(NotifyTask $task, ?string $next, string $now): void
    {
        $this->requireTransaction();
        $changed = $this->model
            ->where('notify_no', $task['notify_no'])
            ->where('status', NotifyConstant::STATUS_SENDING)
            ->where('claim_token', $task['claim_token'])
            ->where('retry_count', (int) $task['retry_count'])
            ->where('lease_until', '<=', $now)
            ->update([
                'status' =>
                    $next === null
                        ? NotifyConstant::STATUS_EXHAUSTED
                        : NotifyConstant::STATUS_PENDING,
                'next_retry_at' => $next,
                'last_response' => 'lease_expired_unknown',
                'manual_attempt_no' => null,
                'claim_token' => null,
                'lease_until' => null,
                'updated_at' => $now,
            ]);
        if ($changed !== 1) {
            throw new \RuntimeException('过期通知租约的恢复条件发生变化');
        }
    }

    /** 已锁定的到期任务自动处理额度耗尽；保留原内容/尝试次数，不能将支付状态回退。 */
    public function exhaust(NotifyTask $task, string $now): void
    {
        $this->requireTransaction();
        $changed = $this->model
            ->where('notify_no', $task['notify_no'])
            ->where('status', (int) $task['status'])
            ->where('retry_count', (int) $task['retry_count'])
            ->update([
                'status' => NotifyConstant::STATUS_EXHAUSTED,
                'next_retry_at' => null,
                'claim_token' => null,
                'lease_until' => null,
                'last_response' => 'automatic_limit_reached',
                'updated_at' => $now,
                'manual_attempt_no' => null,
            ]);
        if ($changed !== 1) {
            throw new \RuntimeException('通知停止自动处理的条件发生变化');
        }
    }

    /**
     * 确定的冻结内容/调度/审计损坏停止自动派发；保留原任务、参数及已有尝试，不伪造丢失的网络历史。
     * 调用方已经锁定任务并排除其他有效租约；EXHAUSTED 加固定原因码供人工核对，不能盲目重置重发。
     */
    public function quarantine(NotifyTask $task, string $reason, string $now): void
    {
        $this->requireTransaction();
        if (
            !in_array(
                $reason,
                ['frozen_payload_invalid', 'attempt_state_invalid', 'schedule_invalid'],
                true,
            )
        ) {
            throw new \InvalidArgumentException('未知通知隔离原因');
        }
        $changed = $this->model
            ->where('notify_no', $task['notify_no'])
            ->where('status', (int) $task['status'])
            ->where('retry_count', (int) $task['retry_count'])
            ->update([
                'status' => NotifyConstant::STATUS_EXHAUSTED,
                'next_retry_at' => null,
                'lease_until' => null,
                'claim_token' => null,
                'last_response' => 'quarantined_' . $reason,
                'updated_at' => $now,
                'manual_attempt_no' => null,
            ]);
        if ($changed !== 1) {
            throw new \RuntimeException('通知隔离条件发生变化');
        }
    }
}
