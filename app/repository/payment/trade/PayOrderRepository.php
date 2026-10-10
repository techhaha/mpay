<?php
declare(strict_types=1);

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\common\constant\TradeConstant;
use app\model\payment\PayOrder;

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

    /**
     * 新建时 pay_amount 为原金额；插件准备后可改为识别金额，原金额及收款证据保存于扩展数据。
     * attempt_no 是数据库固定生成的 1，调用方不能指定第二次尝试。
     * 新支付单不存在时跳过其行锁：先持有 BizOrder 和 ReceiptScope 排他锁再插入，
     * 避免外键共享锁在并发分配时升级死锁。已有单据遵守 BizOrder → PayOrder → ReceiptScope。
     *
     * @param array{pay_no:string, biz_no:string, trace_no:string, poll_group_id:string, route_mode:int, channel_id:string,
     *     channel_revision_id:string, pay_type_id:string, plugin_code:string, pay_amount:int,
     *     receipt_scope_key:string, expire_at:string, ext_json?:string} $data
     * @throws \think\db\exception\PDOException 支付号或业务单有效尝试重复。
     */
    public function create(array $data): string
    {
        return $this->insert($data, ['created_at', 'pay_no', 'biz_no', 'trace_no', 'poll_group_id', 'route_mode', 'channel_id', 'channel_revision_id', 'pay_type_id', 'plugin_code', 'pay_amount', 'receipt_scope_key', 'expire_at', 'ext_json']);
    }

    /**
     * 调用方先持有对应 BizOrder 锁，再锁已有支付单；后续才允许取得 ReceiptScope 锁。
     *
     * @throws \RuntimeException 指定支付单不存在。
     */
    public function lock(string $payNo): PayOrder
    {
        $this->requireTransaction();
        return $this->model->where('pay_no', $payNo)->lock(true)->find() ?? throw new \RuntimeException('支付单不存在');
    }

    /** 只为查询或确定锁目标读取；生命周期仍须按业务单在先的顺序重新取锁。 */
    public function find(string $payNo): ?PayOrder
    {
        return $this->model->where('pay_no', $payNo)->find();
    }

    /**
     * 调用方已锁定通道，查询当天发起且成功/有效待付款的原订单金额与笔数。
     * 主事务的通道锁串行化检查与新尝试插入；独立只读连接取得刚提交的记录，避免 RR 旧快照。
     * 不锁已有业务单/支付单，避免和支付准备、状态推进反向取锁；查询不写入或提交主事务。
     * 仅启用日限制时调用，失败/关闭/已超时单不占用；到账偏移不计入通道限额。
     * @return array{amount:int,count:int}
     */
    public function channelDailyUsage(string $channelId, string $from, string $to, string $now): array
    {
        $this->requireTransaction();
        // 以配置数组作为 ThinkORM 连接键，与名为 mysql 的主事务连接分离。
        $reader = \think\facade\Db::connect(\think\facade\Db::getConfig('connections.mysql'));
        try {
            $row = $reader->table('ma_pay_order')->alias('p')->join('ma_biz_order b', 'b.biz_no=p.biz_no')
                ->where('p.channel_id', $channelId)->where('p.created_at', '>=', $from)->where('p.created_at', '<', $to)
                ->where(function (\think\db\Query $query) use ($now): void {
                    $query->where('p.status', TradeConstant::ORDER_STATUS_SUCCESS)->whereOr(function (\think\db\Query $pending) use ($now): void {
                        $pending->whereIn('p.status', [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING])->where('p.expire_at', '>', $now);
                    });
                })->fieldRaw('COUNT(*) AS used_count, COALESCE(SUM(b.order_amount), 0) AS used_amount')->find();
            return ['amount' => (int) $row['used_amount'], 'count' => (int) $row['used_count']];
        } finally { $reader->close(); }
    }

    /**
     * 插件在范围锁内读取仍等待付款的占用金额；过期订单不再占用当前金额池。
     *
     * @return list<int> 当前 pay_amount，包含其他订单的识别偏移。
     */
    public function usedReceiptAmounts(string $scopeKey, string $exceptPayNo, string $now): array
    {
        $this->requireTransaction();
        $orders = $this->model->where('receipt_scope_key', $scopeKey)
            ->where('pay_no', '<>', $exceptPayNo)->whereIn('status', [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING])
            ->where('expire_at', '>', $now)->where(function (\think\db\Query $prepared): void {
                $prepared->whereNotNull('request_at')->whereOr(function (\think\db\Query $metadata): void {
                    $metadata->whereNotNull('ext_json->personal_receipt->original_amount');
                });
            })->select();
        return array_map(static fn (PayOrder $pay): int => $pay->receiptAmount(), $orders->all());
    }

    /**
     * 按 V2 识别规则提供当前候选，选择最近或要求单候选由具体插件决定。
     * 不在查询内猜支付方式、调整金额或回退到其他账号。
     * 每单按已保存的匹配模式读取：金额单忽略附言，备注单必须同时命中金额和原备注。
     *
     * @return list<PayOrder> 稳定按创建顺序排序，供插件处理等距候选。
     */
    public function receiptCandidates(string $scopeKey, int $amount, ?string $remark, ?string $paidAt, string $now, ?string $channelId = null): array
    {
        $query = $this->model->where('receipt_scope_key', $scopeKey)->where('pay_amount', $amount)
            ->whereIn('status', [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING])
            ->where('expire_at', '>', $now);
        if ($channelId !== null) { $query->where('channel_id', $channelId); }
        $query->where(function (\think\db\Query $mode) use ($remark): void {
            $mode->where('ext_json->personal_receipt->match_mode', 'amount');
            if ($remark !== null && $remark !== '') {
                $mode->whereOr(function (\think\db\Query $code) use ($remark): void {
                    $code->where('ext_json->personal_receipt->match_mode', 'remark')
                        ->where('ext_json->personal_receipt->remark', $remark);
                });
            }
        });
        if ($paidAt !== null) { $query->where('request_at', '<=', $paidAt)->where('expire_at', '>', $paidAt); }
        return $query->order('created_at,id')->select()->all();
    }

    /** 按稳定事件键定位已成功订单；同一到账不能因缺少平台交易号重新分配给待付单。 */
    public function successfulForEvent(string $scopeKey, string $eventKey): ?PayOrder
    {
        return $this->model->where('receipt_scope_key', $scopeKey)->where('status', TradeConstant::ORDER_STATUS_SUCCESS)->where('ext_json->personal_receipt->event_key', $eventKey)->find();
    }

    /** 调用插件已持有业务单、支付单及范围锁；只持久化其准备结果，不决定分配策略。 */
    public function saveReceiptContext(string $payNo, int $amount, array $extension, string $expiresAt, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('pay_no', $payNo)->update(['pay_amount' => $amount, 'expire_at' => $expiresAt,
            'ext_json' => json_encode($extension, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
    }

    /**
     * @param list<string> $numbers 已保存的支付引用，不按金额或时间猜单。
     * @return array<string,PayOrder> 后台流水列表批量关联业务号，空引用不退化为全表读取。
     */
    public function forNumbers(array $numbers): array
    {
        if ($numbers === []) { return []; }
        $result = [];
        foreach ($this->model->whereIn('pay_no', array_values(array_unique($numbers)))->select() as $pay) { $result[$pay['pay_no']] = $pay; }
        return $result;
    }

    /**
     * 新单提交后尚未执行，或明确安全的旧执行租约到期，才进入恢复候选。
     * JSON 路径交给 ThinkORM MySQL 构造器，replay_safe 经 JSON_UNQUOTE 后为字符串 true。
     *
     * @return list<string> 派发服务仍须以原版本及执行 token 再核验，查询条件不是执行授权。
     */
    public function recoverableExecutionNumbers(string $now, int $limit): array
    {
        return $this->model->where('expire_at', '>', $now)->where(function (\think\db\Query $query) use ($now): void {
            $query->where('status', TradeConstant::ORDER_STATUS_CREATED)->whereOr(function (\think\db\Query $retry) use ($now): void {
                $retry->where('status', TradeConstant::ORDER_STATUS_PAYING)
                    ->where('ext_json->payment_context->execution->replay_safe', 'true')
                    ->whereIn('ext_json->payment_context->execution->phase', ['claimed', 'uncertain'])
                    ->where('ext_json->payment_context->execution->lease_until', '<=', $now);
            });
        })->order('updated_at,id')->limit($limit)->column('pay_no');
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
    public function hasSuccessfulTrade(string $scopeKey, string $tradeNo, string $exceptPayNo): bool
    {
        $this->requireTransaction();
        return $this->model->where('receipt_scope_key', $scopeKey)->where('channel_trade_no', $tradeNo)
            ->where('status', TradeConstant::ORDER_STATUS_SUCCESS)->where('pay_no', '<>', $exceptPayNo)->find() !== null;
    }

    /**
     * 用真实范围/交易号定位已经成功的原单，仅供重复或人工补证据，不能据此确认新的订单。
     * 不反向取支付锁；在范围锁事务内调用时须是首次一致性读取，生命周期随后只处理已锁定的同一原单。
     */
    public function successfulForTrade(string $scopeKey, string $tradeNo): ?PayOrder
    {
        return $this->model->where('receipt_scope_key', $scopeKey)->where('channel_trade_no', $tradeNo)
            ->where('status', TradeConstant::ORDER_STATUS_SUCCESS)->find();
    }

    /**
     * 生命周期已经持有行锁并决定合法迁移后，按旧状态条件写入允许的执行/确认字段。
     * 本仓储不决定哪些状态可迁移；禁止同时修改业务身份、通道、金额占用及凭证版本。
     *
     * @param array{status?:int,pay_amount?:int,receipt_flow_id?:string,confirmation_source?:int,
     *     channel_trade_no?:string|null,paid_at?:string,closed_at?:string,failed_at?:string,timeout_at?:string,
     *     request_at?:string,ext_json?:string} $changes 金额为分，JSON 为已编码的内部快照。
     */
    public function saveLifecycle(string $payNo, int $expectedStatus, array $changes, string $now): void
    {
        $this->requireTransaction();
        $fields = ['status', 'pay_amount', 'receipt_flow_id', 'confirmation_source', 'channel_trade_no', 'channel_order_no',
            'paid_at', 'closed_at', 'failed_at', 'timeout_at', 'request_at', 'ext_json'];
        if ($changes === [] || array_diff(array_keys($changes), $fields) !== []) {
            throw new \InvalidArgumentException('支付生命周期写入字段无效');
        }
        $changed = $this->model->where('pay_no', $payNo)->where('status', $expectedStatus)
            ->update($changes + ['updated_at' => $now]);
        if ($changed !== 1) {
            throw new \RuntimeException('支付生命周期写入未改变预期记录');
        }
    }
}
