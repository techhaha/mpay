<?php
declare(strict_types=1);

namespace app\repository\payment\notify;

use app\common\constant\PaymentResultCode as Result;

use app\common\base\BaseRepository;
use app\model\payment\NotifyAttempt;

/**
 * 追加通知派发意图和脱敏结果；始终在对应 NotifyTask 锁之后写，不承担重试策略。
 *
 * @extends BaseRepository<NotifyAttempt>
 */
final class NotifyAttemptRepository extends BaseRepository
{
    public function __construct(NotifyAttempt $model)
    {
        parent::__construct($model);
    }

    /** @return array{items:list<NotifyAttempt>,total:int} 服务需处于同一读取快照，再映射公开字段。 */
    public function pageForNotification(string $notifyNo, int $page, int $limit): array
    {
        return ['items' => $this->model->where('notify_no', $notifyNo)->order('attempt_no', 'desc')->page($page, $limit)->select()->all(),
            'total' => (int) $this->model->where('notify_no', $notifyNo)->count()];
    }

    /**
     * 调用方已持有 NotifyTask 锁，所有尝试写入遵守同一前缀；聚合读取不与正常回写竞争。
     *
     * @return array{count:int,minimum:int,last:NotifyAttempt|null,unfinished_earlier:int} 检查缺号及非当前未结束意图。
     */
    public function ledger(string $notifyNo): array
    {
        $this->requireTransaction();
        $last = $this->model->where('notify_no', $notifyNo)->order('attempt_no', 'desc')->lock(true)->find();
        return ['count' => (int) $this->model->where('notify_no', $notifyNo)->count(),
            'minimum' => (int) $this->model->where('notify_no', $notifyNo)->min('attempt_no'), 'last' => $last,
            'unfinished_earlier' => $last === null ? 0 : (int) $this->model->where('notify_no', $notifyNo)
                ->where('attempt_no', '<>', (int) $last['attempt_no'])->whereNull('finished_at')->count()];
    }

    /**
     * 发送前与认领一起提交。claimed 只证明持久意图，不能据此声称远端已收到或 HTTP 已开始。
     * 崩溃可能发生在网络之前，也可能发生在 ACK 后，必须留存这个不确定窗口。
     */
    public function begin(string $notifyNo, int $attempt, string $token, string $now): void
    {
        $this->insert(['notify_no' => $notifyNo, 'attempt_no' => $attempt, 'claim_token' => $token,
            'started_at' => $now, 'result_code' => Result::CLAIMED], ['notify_no', 'attempt_no', 'claim_token', 'started_at', 'result_code']);
    }

    /**
     * 只完成相同尝试及 token 的未完成记录。调用方须已核对有效租约，或确认该原租约过期。
     *
     * @param array{http_status:?int,duration_ms:?int,result_code:string,response_digest:?string,response_body:?string,response_truncated:bool} $result 正文为已限制的 UTF-8 片段。
     * @throws \RuntimeException 尝试缺失或已完成，回滚本次任务回写，避免责任和审计分裂。
     */
    public function finish(string $notifyNo, int $attempt, string $token, array $result, string $now): void
    {
        $this->requireTransaction();
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $result['result_code'])
            || ($result['response_digest'] !== null && !preg_match('/^[a-f0-9]{64}$/D', $result['response_digest']))
            || ($result['response_body'] !== null && (strlen($result['response_body']) > 4096 || !mb_check_encoding($result['response_body'], 'UTF-8')))
            || !is_bool($result['response_truncated'])) {
            throw new \InvalidArgumentException('通知尝试结果或响应片段格式无效');
        }
        $changed = $this->model->where('notify_no', $notifyNo)->where('attempt_no', $attempt)->where('claim_token', $token)
            ->whereNull('finished_at')->where('result_code', 'claimed')->update($result + [
                'finished_at' => $now, 'updated_at' => $now,
            ]);
        if ($changed !== 1) {
            throw new \RuntimeException('通知尝试缺失或已经完成');
        }
    }
}
