<?php
declare(strict_types=1);

namespace app\repository\payment\runtime;

use app\common\base\BaseRepository;
use app\model\payment\ReceiptRuntimeState;

/**
 * 账号采集运行态的持久化入口；心跳与最后一次成功采集是不同事实。
 *
 * @extends BaseRepository<ReceiptRuntimeState>
 */
final class ReceiptRuntimeStateRepository extends BaseRepository
{
    public function __construct(ReceiptRuntimeState $model)
    {
        parent::__construct($model);
    }

    /**
     * 采集在配置集合之后取得查询运行态锁，读取游标及租约。
     * null 表示没有建立运行记录，不等于空账单或来源健康。
     */
    public function lockForQuery(string $queryKey): ?ReceiptRuntimeState
    {
        $this->requireTransaction();
        return $this->model->where('query_key', $queryKey)->lock(true)->find();
    }

    /** 调用方先锁该账号配置；只初始化等待状态，不把有配置等同于真实查询健康。 */
    public function initialize(string $queryKey, string $now): void
    {
        $this->insert(['query_key' => $queryKey, 'next_run_at' => $now], ['query_key', 'next_run_at']);
    }

    /** 同查询键的配置变化只唤醒任务，不清登录会话，也不破坏正在执行的完整窗口。 */
    public function wake(string $key, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('query_key', $key)->whereNull('claim_token')->update(['next_run_at'=>$now]);
    }

    /** 当前不可采集的账号延后再检查；调用方已经排除其他有效租约，不能抢走活跃工作者。 */
    public function defer(ReceiptRuntimeState $state, int $status, string $reason, ?string $next, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('id', $state['id'])->update([
            'status' => $status, 'reason_code' => $reason, 'next_run_at' => $next,
            'claim_token' => null, 'lease_owner' => null, 'lease_until' => null, 'updated_at' => $now,
        ]);
    }

    /**
     * 短事务中持久认领；保留上一完整采集状态，单纯正在查询不抹去仍有效的健康证据。
     * 保存服务核验过的游标；新建时先保存尚未查完的固定起点。
     */
    public function claim(ReceiptRuntimeState $state, string $token, string $owner, string $now, string $until, string $initialCursor): void
    {
        $this->requireTransaction();
        $changes = ['claim_token' => $token, 'lease_owner' => $owner, 'lease_until' => $until, 'cursor_json' => $initialCursor,
            'last_heartbeat_at' => $now, 'updated_at' => $now];
        $changed = $this->model->where('id', $state['id'])
            ->where(function (\think\db\Query $query) use ($now): void {
                $query->whereNull('lease_until')->whereOr('lease_until', '<=', $now);
            })->update($changes);
        if ($changed !== 1) {
            throw new \RuntimeException('采集运行态认领条件变化');
        }
    }

    /**
     * 每页经过身份/租约核验后保存心跳；不推进完成游标，不延长执行预算。会话由独立仓储保存。
     */
    public function heartbeat(ReceiptRuntimeState $state, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('id', $state['id'])->where('claim_token', $state['claim_token'])
            ->where('lease_until', '>', $now)->update(['last_heartbeat_at'=>$now,'updated_at'=>$now]);
    }

    /**
     * 服务持有配置及运行态锁并核验原身份后回写；禁止改账号归属或由旧 token 写入新状态。
     *
     * @param array{status:int,reason_code:?string,next_run_at:string,retry_count:int,last_duration_ms:int,
     *     last_success_at?:string,cursor_json?:string} $changes
     * @return bool false 表示原租约已失效，不得报告本轮游标提交成功。
     */
    public function finish(ReceiptRuntimeState $state, array $changes, string $now): bool
    {
        $this->requireTransaction();
        if (array_diff(array_keys($changes), ['status', 'reason_code', 'next_run_at', 'retry_count', 'last_duration_ms',
            'last_success_at', 'cursor_json']) !== []) {
            throw new \InvalidArgumentException('采集回写包含不允许字段');
        }
        return $this->model->where('id', $state['id'])->where('claim_token', $state['claim_token'])
            ->where('lease_until', '>', $now)->update($changes + [
                'claim_token' => null, 'lease_owner' => null, 'lease_until' => null,
                'last_heartbeat_at' => $now, 'updated_at' => $now,
            ]) === 1;
    }
}
