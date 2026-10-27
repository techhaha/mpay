<?php
declare(strict_types=1);

namespace app\repository\payment\receipt;

use app\common\base\BaseRepository;
use app\model\payment\ReceiptFlow;
use RuntimeException;
use think\db\Query;

/**
 * 持久化首次到账事实及受控绑定；原金额、时间、来源和摘要在重投时不可覆盖。
 *
 * @extends BaseRepository<ReceiptFlow>
 */
final class ReceiptFlowRepository extends BaseRepository
{
    public function __construct(ReceiptFlow $model)
    {
        parent::__construct($model);
    }

    /** 非锁定读取只用于定位；匹配和绑定必须在范围锁之后重新读取。 */
    public function find(string $id): ?ReceiptFlow
    {
        return $this->model->find($id);
    }

    /**
     * 本批先查询已有消费，避免成功订单不在待付集合后，旧流水被匹配到另一订单。
     * 此读取仅供插件排除，最终确认仍由唯一键和锁内复核保证。
     * @return list<ReceiptFlow>
     */
    public function consumed(string $plugin, array $events, array $trades): array
    {
        if ($events === [] && $trades === []) {
            return [];
        }
        return $this->model
            ->where('plugin_code', $plugin)
            ->where(function (Query $query) use ($events, $trades): void {
                $query->whereIn('event_key', $events);
                if ($trades !== []) {
                    $query->whereOr('platform_trade_no', 'in', $trades);
                }
            })
            ->select()
            ->all();
    }

    /**
     * @param array{from?:string,until?:string,id?:string,scope_key?:string,plugin_code?:string,pay_no?:string} $filters 按系统接收时间筛选，日期缺省时查询全部。
     * @return array{items:list<ReceiptFlow>,total:int} 列表只读投影；完整证据由详情读取，服务拥有一致事务。
     */
    public function adminPage(array $filters, int $page, int $limit): array
    {
        $query = $this->model->where('id', '>', 0);
        if (isset($filters['from'], $filters['until'])) {
            $query
                ->where('received_at', '>=', $filters['from'])
                ->where('received_at', '<', $filters['until']);
        }
        foreach (
            [
                'id' => 'id',
                'scope_key' => 'receipt_scope_key',
                'plugin_code' => 'plugin_code',
                'pay_no' => 'pay_no',
            ]
            as $key => $column
        ) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        return [
            'total' => (int) (clone $query)->count(),
            'items' => $query
                ->field(
                    'id,receipt_scope_key,plugin_code,reason_code,amount,paid_at,received_at,platform_trade_no,pay_no',
                )
                ->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->all(),
        ];
    }

    /** 调用前已锁对应 ReceiptScope；生命周期还必须先锁业务单及支付单。 */
    public function lock(string $id): ReceiptFlow
    {
        $this->requireTransaction();
        return $this->model->where('id', $id)->lock(true)->find() ??
            throw new RuntimeException('到账流水不存在');
    }

    /** 稳定身份采用插件 + 事件键，不随通道配置改变；null 只表示当前未持久化，唯一键仍承担最终保护。 */
    public function lockByEvent(string $pluginCode, string $eventKey): ?ReceiptFlow
    {
        $this->requireTransaction();
        return $this->model
            ->where('plugin_code', $pluginCode)
            ->where('event_key', $eventKey)
            ->lock(true)
            ->find();
    }

    /** 平台交易号在插件内唯一，查询窗口或配置别名重叠不能重复消费同一交易。 */
    public function lockByTrade(string $plugin, string $trade): ?ReceiptFlow
    {
        $this->requireTransaction();
        return $this->model
            ->where('plugin_code', $plugin)
            ->where('platform_trade_no', $trade)
            ->lock(true)
            ->find();
    }

    /**
     * 生命周期已锁订单和范围并确认最终匹配；只插入最终绑定，失败由同一成功事务回滚。
     *
     * @param array{receipt_scope_key:string,plugin_code:string,
     *     event_key:string,payload_digest:string,platform_trade_no:?string,
     *     amount:int,remark:?string,paid_at:?string,received_at:string,pay_no:string,
     *     reason_code:?string,evidence_json:string} $data 金额为分，时间为上海时区秒精度。
     */
    public function create(array $data): string
    {
        $this->requireTransaction();
        return $this->insert($data, [
            'receipt_scope_key',
            'plugin_code',
            'event_key',
            'payload_digest',
            'platform_trade_no',
            'amount',
            'remark',
            'paid_at',
            'received_at',
            'pay_no',
            'reason_code',
            'evidence_json',
        ]);
    }
}
