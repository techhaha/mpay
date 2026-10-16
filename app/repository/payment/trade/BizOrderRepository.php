<?php
declare(strict_types=1);

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\common\constant\NotifyConstant;
use app\common\constant\TradeConstant;
use app\model\payment\BizOrder;
use InvalidArgumentException;
use RuntimeException;
use think\db\exception\PDOException;
use think\db\Query;

/**
 * 保存业务需求与外部订单身份，提供业务单行锁及首次支付尝试关联；不判断收款成功。
 *
 * @extends BaseRepository<BizOrder>
 */
final class BizOrderRepository extends BaseRepository
{
    public function __construct(BizOrder $model)
    {
        parent::__construct($model);
    }

    /** @return list<string> 到期候选号；生命周期逐条锁定并核验，扫描本身不改变支付事实。 */
    public function dueExpirationNumbers(string $now, int $limit): array
    {
        return $this->model
            ->whereIn('status', [
                TradeConstant::ORDER_STATUS_CREATED,
                TradeConstant::ORDER_STATUS_PAYING,
            ])
            ->where('expire_at', '<=', $now)
            ->order('expire_at,id')
            ->limit($limit)
            ->column('biz_no');
    }

    /**
     * 由创建服务核验字段、凭证版本和幂等参数后，在业务事务内插入。
     * order_amount 单位为分；订单号保持大小写和原始字节语义。
     *
     * @param array{biz_no:string,trace_no:string,app_id:string,pid:string,merchant_order_no:string,
     *     subject:string,order_amount:int,credential_version_id:string,access_token_hash:string,
     *     expire_at:string,notify_url:?string,
     *     client_ip?:string|null,return_url?:string|null,ext_json?:string,created_at?:string} $data
     * @throws PDOException 唯一身份竞争交由创建服务分类，不在此吞掉冲突。
     */
    public function create(array $data): string
    {
        return $this->insert($data, [
            'created_at',
            'biz_no',
            'trace_no',
            'app_id',
            'pid',
            'merchant_order_no',
            'subject',
            'order_amount',
            'credential_version_id',
            'access_token_hash',
            'client_ip',
            'expire_at',
            'notify_url',
            'return_url',
            'ext_json',
        ]);
    }

    /**
     * 按应用与原始外部订单号查询，VARBINARY 列保留大小写和原始字节语义。
     * null 仅表示此次未查到记录；并发创建仍须依靠唯一约束，不把先查后插当成幂等保护。
     */
    public function findByAppOrder(string $appId, string $merchantOrderNo): ?BizOrder
    {
        return $this->model
            ->where('app_id', $appId)
            ->where('merchant_order_no', $merchantOrderNo)
            ->find();
    }

    /**
     * 建单幂等入口的当前读；调用方先持有应用行锁，串行化首次插入及凭证轮换。
     * 使用与无锁查询相同的大小写敏感身份，缺失行最终仍由数据库唯一约束保护。
     */
    public function lockByAppOrder(string $appId, string $merchantOrderNo): ?BizOrder
    {
        $this->requireTransaction();
        return $this->model
            ->where('app_id', $appId)
            ->where('merchant_order_no', $merchantOrderNo)
            ->lock(true)
            ->find();
    }

    /** 无锁读取访问凭证对应的业务单；后续修改必须重新加锁并核验凭证和状态。 */
    public function find(string $bizNo): ?BizOrder
    {
        return $this->model->where('biz_no', $bizNo)->find();
    }

    /** 收银台高频读取只取身份、状态和导航事实，不加载商品、金额及完整扩展配置。 */
    public function cashierState(string $bizNo, bool $completion = false): ?BizOrder
    {
        $query = $this->model
            ->where('biz_no', $bizNo)
            ->field('biz_no,access_token_hash,status,active_pay_no,expire_at');
        if ($completion) {
            $query->fieldRaw(
                'notify_url IS NOT NULL AS cashier_notify_required, return_url IS NOT NULL AS cashier_has_return',
            );
        }
        return $query->find();
    }

    /** @return list<BizOrder> 按已认证应用分页；page/limit 由协议校验器限定，不读取整表后切片。 */
    public function pageForApp(string $appId, int $page, int $limit): array
    {
        return $this->model
            ->where('app_id', $appId)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->all();
    }

    /** 按业务单身份计数，不与支付/流水表联接造成重复；时间范围为左闭右开的上海时区日期。 */
    public function countForApp(string $appId, ?string $from = null, ?string $until = null): int
    {
        $query = $this->model->where('app_id', $appId);
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($until !== null) {
            $query->where('created_at', '<', $until);
        }
        return (int) $query->count();
    }

    /**
     * 后台列表与导出共用筛选；服务已校验日期、状态和订单号，不接收任意查询列或排序表达式。
     *
     * @param array{from:string,until:string,status:?int,notification_status?:?string,biz_no:?string,pay_no:?string,merchant_order_no:?string} $filters
     * @return list<BizOrder>
     */
    public function adminPage(array $filters, int $page, int $limit): array
    {
        return $this->adminQuery($filters)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->all();
    }

    /** @param array{from:string,until:string,status:?int,notification_status?:?string,biz_no:?string,pay_no:?string,merchant_order_no:?string} $filters */
    public function adminCount(array $filters): int
    {
        return (int) $this->adminQuery($filters)->count();
    }

    /**
     * 导出按主键向前读取，不使用大偏移分页；一致快照和总行数限制由服务拥有。
     *
     * @param array{from:string,until:string,status:?int,notification_status?:?string,biz_no:?string,pay_no:?string,merchant_order_no:?string} $filters
     * @return list<BizOrder>
     */
    public function exportBatch(array $filters, string $afterId, int $limit): array
    {
        return $this->adminQuery($filters)
            ->where('id', '>', $afterId)
            ->order('id')
            ->limit($limit)
            ->select()
            ->all();
    }

    /** @param array{from:string,until:string,status:?int,notification_status?:?string,biz_no:?string,pay_no:?string,merchant_order_no:?string} $filters */
    private function adminQuery(array $filters): Query
    {
        $query = $this->model
            ->where('created_at', '>=', $filters['from'])
            ->where('created_at', '<', $filters['until']);
        foreach (
            [
                'app_id' => 'app_id',
                'pid' => 'pid',
                'status' => 'status',
                'biz_no' => 'biz_no',
                'pay_no' => 'active_pay_no',
                'merchant_order_no' => 'merchant_order_no',
            ]
            as $key => $column
        ) {
            if (($filters[$key] ?? null) !== null) {
                $query->where($column, $filters[$key]);
            }
        }
        if (
            ($filters['poll_group_id'] ?? null) !== null ||
            ($filters['channel_id'] ?? null) !== null ||
            ($filters['pay_type'] ?? null) !== null
        ) {
            // ORM 子查询限定支付尝试，不 JOIN 放大业务单列表或导出计数。
            $query->whereIn('biz_no', static function (Query $payments) use ($filters): void {
                $payments->table('ma_pay_order')->field('biz_no');
                foreach (['poll_group_id', 'channel_id'] as $field) {
                    if (($filters[$field] ?? null) !== null) {
                        $payments->where($field, $filters[$field]);
                    }
                }
                if (($filters['pay_type'] ?? null) !== null) {
                    $payments->whereIn('pay_type_id', static function (Query $types) use (
                        $filters,
                    ): void {
                        $types
                            ->table('ma_payment_type')
                            ->field('id')
                            ->where('code', $filters['pay_type']);
                    });
                }
            });
        }
        if (($filters['notification_status'] ?? null) !== null) {
            // 按通知责任筛选，发送尝试是一对多，不能 JOIN 尝试表放大订单和导出计数。
            $condition = static function (Query $tasks) use ($filters): void {
                $tasks
                    ->table('ma_notify_task')
                    ->field('biz_no')
                    ->where('event_type', NotifyConstant::EVENT_PAY_SUCCESS);
                if ($filters['notification_status'] === 'acknowledged') {
                    $tasks->whereNotNull('first_ack_at');
                } elseif ($filters['notification_status'] !== 'none') {
                    $tasks->where('status', (int) $filters['notification_status']);
                }
            };
            if ($filters['notification_status'] === 'none') {
                $query->whereNotIn('biz_no', $condition);
            } else {
                $query->whereIn('biz_no', $condition);
            }
        }
        return $query;
    }

    /**
     * 在首次支付准备事务中缩短业务单有效期到实际通道窗口，不延长创建时的期限。
     * 调用者已持有业务单锁；只保存服务计算的截止时间，不推进任何支付状态。
     */
    public function shortenExpiry(string $bizNo, string $expireAt): void
    {
        $this->requireTransaction();
        $this->model
            ->where('biz_no', $bizNo)
            ->where('expire_at', '>', $expireAt)
            ->update(['expire_at' => $expireAt, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 交易锁顺序的起点；锁定后才可继续取对应 PayOrder 和收款范围锁。
     *
     * @throws RuntimeException 指定业务单不存在。
     */
    public function lock(string $bizNo): BizOrder
    {
        $this->requireTransaction();
        return $this->model->where('biz_no', $bizNo)->lock(true)->find() ??
            throw new RuntimeException('业务单不存在');
    }

    /**
     * 在同一创建事务中关联首次支付尝试；调用方须先锁业务单并完成支付单插入。
     * 重复调用属于状态冲突，不能拿另一个支付号覆盖原关联。
     *
     * @throws RuntimeException 支付单不属于该业务单，或业务单已经关联过支付尝试。
     */
    public function attachAttempt(string $bizNo, string $payNo): void
    {
        $this->requireTransaction();
        $biz = $this->model->where('biz_no', $bizNo)->find();
        if ($biz === null || $biz->payOrder()->where('pay_no', $payNo)->find() === null) {
            throw new RuntimeException('支付单与业务单不一致');
        }
        // 条件更新与影响行数复核共同拒绝重复关联，不只依靠上面的关联存在性查询。
        $affected = $this->model
            ->where('biz_no', $bizNo)
            ->where('attempt_count', 0)
            ->update([
                'active_pay_no' => $payNo,
                'attempt_count' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('业务单已存在支付尝试或支付身份不一致');
        }
    }

    /**
     * 生命周期在已锁定业务单后同步状态及业务金额，不允许改原始下单参数或尝试身份。
     *
     * @param array{status:int,paid_amount?:int,paid_at?:string,closed_at?:string,failed_at?:string,timeout_at?:string} $changes
     */
    public function saveLifecycle(
        string $bizNo,
        int $expectedStatus,
        array $changes,
        string $now,
    ): void {
        $this->requireTransaction();
        if (
            !isset($changes['status']) ||
            array_diff(array_keys($changes), [
                'status',
                'paid_amount',
                'paid_at',
                'closed_at',
                'failed_at',
                'timeout_at',
            ]) !== []
        ) {
            throw new InvalidArgumentException('业务生命周期写入字段无效');
        }
        $changed = $this->model
            ->where('biz_no', $bizNo)
            ->where('status', $expectedStatus)
            ->update($changes + ['updated_at' => $now]);
        if ($changed !== 1) {
            throw new RuntimeException('业务生命周期写入未改变预期记录');
        }
    }
}
