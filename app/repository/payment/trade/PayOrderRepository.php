<?php
declare(strict_types=1);

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\common\constant\TradeConstant;
use app\model\payment\PayOrder;
use InvalidArgumentException;
use RuntimeException;
use think\db\exception\PDOException;
use think\db\Query;
use think\facade\Db;

/**
 * 保存具体支付尝试和提示付款金额；成功确认、状态流转及通知责任由生命周期服务统一处理。
 *
 * @extends BaseRepository<PayOrder>
 */
final class PayOrderRepository extends BaseRepository
{
    public function __construct(PayOrder $model)
    {
        parent::__construct($model);
    }

    /** 固定本轮上界，新插入订单下轮读取；不得按最近 N 条覆盖掉长期待核对订单。 */
    public function collectionUpperId(): string
    {
        return (string) ($this->model->max('id') ?? '0');
    }

    /** @return list<string> 本轮待核对订单引用的插件；不依赖当前通道或插件开关。 */
    public function collectionPlugins(string $since, string $upper): array
    {
        return $this->collectionQuery($since, $upper)->distinct(true)->column('plugin_code');
    }

    /**
     * 分页只控制订单取数，不拆分插件的账号查询；通道版本由采集服务合并 ID 后一次批读。
     * 停用通道不取消原订单责任，到期/本地关闭在给定补查范围内仍返回。
     * @return list<PayOrder>
     */
    public function collectionPage(
        string $plugin,
        string $since,
        string $after,
        string $upper,
        int $limit,
    ): array {
        return $this->collectionQuery($since, $upper)
            ->where('plugin_code', $plugin)
            ->where('id', '>', $after)
            ->order('id')
            ->limit($limit)
            ->select()
            ->all();
    }

    /** 插件发现与订单分页共用状态、补查窗口及本轮上界，避免漏掉停用通道的原单。 */
    private function collectionQuery(string $since, string $upper): Query
    {
        return $this->model
            ->whereIn('status', [
                TradeConstant::ORDER_STATUS_CREATED,
                TradeConstant::ORDER_STATUS_PAYING,
                TradeConstant::ORDER_STATUS_CLOSED,
                TradeConstant::ORDER_STATUS_TIMEOUT,
            ])
            ->whereNotNull('request_at')
            ->where('expire_at', '>=', $since)
            ->where('id', '<=', $upper);
    }

    /**
     * 新建时 pay_amount 为原金额；插件准备后可改为识别金额，原金额及收款证据保存于扩展数据。
     * attempt_no 是数据库固定生成的 1，调用方不能指定第二次尝试。
     * 新支付单不存在时跳过其行锁：先持有 BizOrder 和 ReceiptScope 排他锁再插入，
     * 避免外键共享锁在并发分配时升级死锁。已有单据遵守 BizOrder → PayOrder → ReceiptScope。
     *
     * @param array{pay_no:string, biz_no:string, trace_no:string, poll_group_id:string, route_mode:int, channel_id:string,
     *     channel_revision_id:string, pay_type_id:string, plugin_code:string, pay_amount:int,
     *     receipt_scope_key:string, expire_at:string, ext_json?:string} $data
     * @throws PDOException 支付号或业务单有效尝试重复。
     */
    public function create(array $data): string
    {
        return $this->insert($data, [
            'created_at',
            'pay_no',
            'biz_no',
            'trace_no',
            'poll_group_id',
            'route_mode',
            'channel_id',
            'channel_revision_id',
            'pay_type_id',
            'plugin_code',
            'pay_amount',
            'receipt_scope_key',
            'expire_at',
            'ext_json',
        ]);
    }

    /**
     * 调用方先持有对应 BizOrder 锁，再锁已有支付单；后续才允许取得 ReceiptScope 锁。
     *
     * @throws RuntimeException 指定支付单不存在。
     */
    public function lock(string $payNo): PayOrder
    {
        $this->requireTransaction();
        return $this->model->where('pay_no', $payNo)->lock(true)->find() ??
            throw new RuntimeException('支付单不存在');
    }

    /** 只为查询或确定锁目标读取；生命周期仍须按业务单在先的顺序重新取锁。 */
    public function find(string $payNo): ?PayOrder
    {
        return $this->model->where('pay_no', $payNo)->find();
    }

    /**
     * 付款状态投影不传输完整 JSON、二维码或收款证据；JSON 路径为代码常量，兼容 MySQL 5.7.9。
     * 这些别名仅用于只读收银台，不交给模型保存。
     */
    public function cashierState(string $payNo): ?PayOrder
    {
        return $this->model
            ->where('pay_no', $payNo)
            ->field('pay_no,biz_no,status,expire_at,request_at')
            ->fieldRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(ext_json, '$.payment_context.execution.phase')) AS cashier_phase,
                JSON_UNQUOTE(JSON_EXTRACT(ext_json, '$.payment_context.execution.lease_until')) AS cashier_lease_until,
                JSON_CONTAINS_PATH(ext_json, 'one', '$.presentation') AS cashier_has_presentation",
            )
            ->find();
    }

    /**
     * 调用方已锁定本批全部通道，按通道汇总当天成功/有效待付款的原金额与笔数。
     * 主事务的通道锁串行化检查与新尝试插入；本批共用一次独立读取，避免 RR 旧快照。
     * 不锁已有业务单/支付单，避免和支付准备、状态推进反向取锁；查询不写入或提交主事务。
     * 仅启用日限制时调用，失败/关闭/已超时单不占用；到账偏移不计入通道限额。
     *
     * @param list<string> $channelIds 启用了日限制的候选通道；正式选路已持通道锁，预览只读取额度。
     * @return array<string,array{amount:int,count:int}> 无占用的通道没有分组行，由调用方按零处理。
     */
    public function channelDailyUsages(
        array $channelIds,
        string $from,
        string $to,
        string $now,
    ): array {
        $this->requireTransaction();
        if ($channelIds === []) {
            return [];
        }
        // 以配置数组作为 ThinkORM 连接键，与名为 mysql 的主事务连接分离。
        $reader = Db::connect(Db::getConfig('connections.mysql'));
        try {
            $rows = $reader
                ->table('ma_pay_order')
                ->alias('p')
                ->join('ma_biz_order b', 'b.biz_no=p.biz_no')
                ->whereIn('p.channel_id', array_values(array_unique($channelIds)))
                ->where('p.created_at', '>=', $from)
                ->where('p.created_at', '<', $to)
                ->where(function (Query $query) use ($now): void {
                    $query
                        ->where('p.status', TradeConstant::ORDER_STATUS_SUCCESS)
                        ->whereOr(function (Query $pending) use ($now): void {
                            $pending
                                ->whereIn('p.status', [
                                    TradeConstant::ORDER_STATUS_CREATED,
                                    TradeConstant::ORDER_STATUS_PAYING,
                                ])
                                ->where('p.expire_at', '>', $now);
                        });
                })
                ->field('p.channel_id')
                ->fieldRaw(
                    'COUNT(*) AS used_count, COALESCE(SUM(b.order_amount), 0) AS used_amount',
                )
                ->group('p.channel_id')
                ->select();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['channel_id']] = [
                    'amount' => (int) $row['used_amount'],
                    'count' => (int) $row['used_count'],
                ];
            }
            return $result;
        } finally {
            $reader->close();
        }
    }

    /**
     * 插件在同一通道锁内分页读取有效待付订单；只提供候选，不解释快照中的识别策略。
     * 调用方须在取得通道锁后再开始一致性读取，不对其他订单加锁，避免反向锁序。
     * @return iterable<PayOrder> 初始化金额是否构成占用，由插件核对准备快照后决定。
     */
    public function channelPending(string $channelId, string $exceptPayNo, string $now): iterable
    {
        $this->requireTransaction();
        $after = '0';
        do {
            $rows = $this->model
                ->where('channel_id', $channelId)
                ->where('pay_no', '<>', $exceptPayNo)
                ->whereIn('status', [
                    TradeConstant::ORDER_STATUS_CREATED,
                    TradeConstant::ORDER_STATUS_PAYING,
                ])
                ->where('expire_at', '>', $now)
                ->where('id', '>', $after)
                ->field('id,pay_no,pay_amount,ext_json')
                ->order('id')
                ->limit(500)
                ->select();
            foreach ($rows as $row) {
                $after = $row['id'];
                yield $row;
            }
        } while (count($rows) === 500);
    }

    /**
     * 按明确通道、插件、金额及调用插件给出的时间条件提供候选；备注和模式只由插件解释。
     * 本地关闭/超时保留原付款窗口。分页取齐候选，不能截断后宣称唯一。
     * paidUntil 仅用于来源时间具有精度区间的情况；缺省仍按原 paidAt 秒值查询。
     * 区间与微信消息窗口的交集由银行插件计算，本仓储不解释银行格式。
     * @return iterable<PayOrder>
     */
    public function receiptCandidates(
        string $channelId,
        string $plugin,
        int $amount,
        ?string $paidAt,
        string $now,
        ?string $paidUntil = null,
    ): iterable {
        if ($paidUntil !== null && ($paidAt === null || $paidUntil < $paidAt)) {
            throw new InvalidArgumentException('收款候选时间区间无效');
        }
        $after = '0';
        do {
            $query = $this->model
                ->where('channel_id', $channelId)
                ->where('plugin_code', $plugin)
                ->where('pay_amount', $amount)
                ->whereIn('status', [
                    TradeConstant::ORDER_STATUS_CREATED,
                    TradeConstant::ORDER_STATUS_PAYING,
                    TradeConstant::ORDER_STATUS_CLOSED,
                    TradeConstant::ORDER_STATUS_TIMEOUT,
                ])
                ->whereNotNull('request_at')
                ->where('id', '>', $after);
            if ($paidAt === null) {
                $query->where('expire_at', '>', $now);
            } else {
                $query
                    ->where('request_at', '<=', $paidUntil ?? $paidAt)
                    ->where('expire_at', '>', $paidAt);
            }
            $rows = $query->order('id')->limit(500)->select();
            foreach ($rows as $row) {
                $after = $row['id'];
                yield $row;
            }
        } while (count($rows) === 500);
    }

    /** 事件唯一键先定位已匹配流水，再核对通道；不遍历成功订单的 JSON 事件字段。 */
    public function successfulForEvent(
        string $channelId,
        string $plugin,
        string $eventKey,
    ): ?PayOrder {
        return $this->model
            ->alias('p')
            ->join('ma_receipt_flow f', 'f.pay_no=p.pay_no')
            ->where('f.plugin_code', $plugin)
            ->where('f.event_key', $eventKey)
            ->where('p.channel_id', $channelId)
            ->where('p.plugin_code', $plugin)
            ->where('p.status', TradeConstant::ORDER_STATUS_SUCCESS)
            ->field('p.*')
            ->find();
    }

    /** 调用插件已持有业务单、支付单及通道锁；只持久化其准备结果，不决定分配策略。 */
    public function saveReceiptContext(
        string $payNo,
        int $amount,
        array $extension,
        string $expiresAt,
        string $now,
    ): void {
        $this->requireTransaction();
        $this->model->where('pay_no', $payNo)->update([
            'pay_amount' => $amount,
            'expire_at' => $expiresAt,
            'ext_json' => json_encode($extension, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ]);
    }

    /**
     * @param list<string> $numbers 已保存的支付引用，不按金额或时间猜单。
     * @return array<string,PayOrder> 后台流水列表的只读关联投影，空引用不退化为全表读取；不用于交易更新。
     */
    public function forNumbers(array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }
        $result = [];
        foreach (
            $this->model
                ->whereIn('pay_no', array_values(array_unique($numbers)))
                ->field('pay_no,biz_no,receipt_scope_key')
                ->select()
            as $pay
        ) {
            $result[$pay['pay_no']] = $pay;
        }
        return $result;
    }

    /**
     * @param list<string> $bizNos 已按认证主体筛出的业务号，空列表不会退化成全表查询。
     * @return array<string,PayOrder> 按 biz_no 索引，供分页适配一次取齐唯一支付尝试。
     */
    public function forBizOrders(array $bizNos): array
    {
        if ($bizNos === []) {
            return [];
        }
        $result = [];
        foreach ($this->model->whereIn('biz_no', $bizNos)->select() as $pay) {
            $result[$pay['biz_no']] = $pay;
        }
        return $result;
    }

    /**
     * 核对真实交易号是否已用于另一笔成功，包括未关联自动流水的人工确认。
     * 调用者已持有范围锁，且此查询前未建立一致性快照；其他确认也受同一范围锁串行化。
     * 这里不能反向锁其他 PayOrder（它可能正等待范围锁）；P2 追加迁移的唯一索引承担最终约束。
     */
    public function hasSuccessfulTrade(
        string $pluginCode,
        string $tradeNo,
        string $exceptPayNo,
    ): bool {
        $this->requireTransaction();
        return $this->model
            ->where('plugin_code', $pluginCode)
            ->where('channel_trade_no', $tradeNo)
            ->where('status', TradeConstant::ORDER_STATUS_SUCCESS)
            ->where('pay_no', '<>', $exceptPayNo)
            ->find() !== null;
    }

    /**
     * 生命周期已经持有行锁并决定合法迁移后，按旧状态条件写入允许的执行/确认字段。
     * 本仓储不决定哪些状态可迁移；禁止同时修改业务身份、通道、金额占用及凭证版本。
     *
     * @param array{status?:int,pay_amount?:int,receipt_flow_id?:string,confirmation_source?:int,
     *     channel_trade_no?:string|null,paid_at?:string,closed_at?:string,failed_at?:string,timeout_at?:string,
     *     request_at?:string,ext_json?:string} $changes 金额为分，JSON 为已编码的内部快照。
     */
    public function saveLifecycle(
        string $payNo,
        int $expectedStatus,
        array $changes,
        string $now,
    ): void {
        $this->requireTransaction();
        $fields = [
            'status',
            'pay_amount',
            'receipt_flow_id',
            'confirmation_source',
            'channel_trade_no',
            'channel_order_no',
            'paid_at',
            'closed_at',
            'failed_at',
            'timeout_at',
            'request_at',
            'ext_json',
        ];
        if ($changes === [] || array_diff(array_keys($changes), $fields) !== []) {
            throw new InvalidArgumentException('支付生命周期写入字段无效');
        }
        $changed = $this->model
            ->where('pay_no', $payNo)
            ->where('status', $expectedStatus)
            ->update($changes + ['updated_at' => $now]);
        if ($changed !== 1) {
            throw new RuntimeException('支付生命周期写入未改变预期记录');
        }
    }
}
