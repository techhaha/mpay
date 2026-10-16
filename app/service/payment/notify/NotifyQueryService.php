<?php
declare(strict_types=1);

namespace app\service\payment\notify;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\constant\NotifyConstant;
use app\exception\BusinessException;
use app\model\payment\NotifyTask;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\notify\NotifyAttemptRepository;

/**
 * 后台通知与尝试的只读视图，保留原业务关联；不把冻结签名正文、URL query 或认领 token 暴露给浏览器。
 * @phpstan-type NotificationView array{notify_no:string,biz_no:string,pay_no:string,status:int,status_label:string,
 *     retry_count:int,manual_attempt_no:?int,next_retry_at:?string,last_notify_at:?string,created_at:string,
 *     reason_code:?string,reason_label:string,first_ack_at:?string,released_at:?string,target_host:string}
 */
final class NotifyQueryService extends BaseService
{
    public function __construct(
        private readonly NotifyTaskRepository $tasks,
        private readonly NotifyAttemptRepository $attempts,
    ) {}

    /**
     * @param array{from?:string,until?:string,app_id?:string,notify_no?:string,pay_no?:string,biz_no?:string,status?:int|'acknowledged'} $filters acknowledged 查询曾获得有效商户应答的任务。
     * @return array{items:list<NotificationView>,total:int,page:int,limit:int}
     */
    public function listing(array $filters, int $page, int $limit): array
    {
        return $this->transaction(function () use ($filters, $page, $limit): array {
            $data = $this->tasks->adminPage($filters, $page, $limit);
            return [
                'items' => array_map(
                    fn(NotifyTask $task): array => $this->summary($task),
                    $data['items'],
                ),
                'total' => $data['total'],
                'page' => $page,
                'limit' => $limit,
            ];
        });
    }

    /** @return NotificationView 状态只说明业务应答与调度，不改变或重新解释付款状态。 */
    public function detail(string $notifyNo): array
    {
        return $this->summary(
            $this->tasks->find($notifyNo) ??
                throw new BusinessException('通知任务不存在', ApiResponseConstant::NOT_FOUND),
        );
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int} 不截掉早期失败/未知尝试。 */
    public function attempts(string $notifyNo, int $page, int $limit): array
    {
        return $this->transaction(function () use ($notifyNo, $page, $limit): array {
            if ($this->tasks->find($notifyNo) === null) {
                throw new BusinessException('通知任务不存在', ApiResponseConstant::NOT_FOUND);
            }
            $data = $this->attempts->pageForNotification($notifyNo, $page, $limit);
            $items = [];
            foreach ($data['items'] as $attempt) {
                $row = [];
                foreach (
                    [
                        'id',
                        'attempt_no',
                        'started_at',
                        'finished_at',
                        'http_status',
                        'duration_ms',
                        'result_code',
                        'response_digest',
                        'response_body',
                        'response_truncated',
                    ]
                    as $field
                ) {
                    $row[$field] = $attempt[$field];
                }
                $row['result_code'] = $this->reason($row['result_code']);
                $row['result_label'] = $this->reasonLabel($row['result_code']);
                $items[] = $row;
            }
            return [
                'items' => $items,
                'total' => $data['total'],
                'page' => $page,
                'limit' => $limit,
            ];
        });
    }

    /** @return NotificationView|null 与订单详情共用同一个通知白名单，避免维护两套展示口径。 */
    public function summary(?NotifyTask $task): ?array
    {
        if ($task === null) {
            return null;
        }
        $reason = $this->reason($task['last_response']);
        return [
            'notify_no' => $task['notify_no'],
            'biz_no' => $task['biz_no'],
            'pay_no' => $task['pay_no'],
            'status' => (int) $task['status'],
            'status_label' => match ((int) $task['status']) {
                NotifyConstant::STATUS_PENDING => $task['first_ack_at'] === null
                    ? '待发送'
                    : '补发待发送',
                NotifyConstant::STATUS_SENDING => $task['first_ack_at'] === null
                    ? '发送中'
                    : '补发发送中',
                NotifyConstant::STATUS_SUCCESS => '业务网站已确认',
                NotifyConstant::STATUS_EXHAUSTED => $task['first_ack_at'] === null
                    ? '已停止自动重试'
                    : '最近补发未确认',
                NotifyConstant::STATUS_HELD => '暂不通知',
                default => throw new \RuntimeException('通知状态无效'),
            },
            'retry_count' => (int) $task['retry_count'],
            'manual_attempt_no' => $task['manual_attempt_no'],
            'first_ack_at' => $task['first_ack_at'],
            'released_at' => $task['released_at'],
            'next_retry_at' => $task['next_retry_at'],
            'last_notify_at' => $task['last_notify_at'],
            'created_at' => $task['created_at'],
            'target_host' => (string) parse_url($task['notify_url'], PHP_URL_HOST),
            'reason_code' => $reason,
            'reason_label' => $reason === null ? '尚未发送' : $this->reasonLabel($reason),
        ];
    }

    private function reason(?string $value): ?string
    {
        return $value !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) ? $value : null;
    }

    /** 固定解释不回显上游正文；未知码保留受限代码以供排障，不猜测远端是否已经处理。 */
    public function reasonLabel(?string $reason): string
    {
        return \app\common\constant\PaymentResultCode::label($reason);
    }
}
