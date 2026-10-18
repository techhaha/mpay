<?php
declare(strict_types=1);

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\common\constant\TradeConstant;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentChannelRevision;
use app\model\payment\PayOrder;
use InvalidArgumentException;
use RuntimeException;

/**
 * 通道规则和历史快照的持久化；快照生成及账号归属检查由 PaymentChannelRevisionService 完成。
 *
 * @extends BaseRepository<PaymentChannel>
 */
final class PaymentChannelRepository extends BaseRepository
{
    public function __construct(
        PaymentChannel $model,
        private readonly PaymentChannelRevision $revisions,
        private readonly PayOrder $payments,
    ) {
        parent::__construct($model);
    }

    /** @return list<PaymentChannel> 仅新增保活来源，存量订单由独立订单查询装配，不依赖当前开关。 */
    public function preloginChannels(string $plugin): array
    {
        return $this->model
            ->alias('ch')
            ->join('ma_payment_plugin p', 'p.code=ch.plugin_code')
            ->join('ma_payment_plugin_conf c', 'c.id=ch.api_config_id')
            ->where('ch.plugin_code', $plugin)
            ->where('ch.enabled', 1)
            ->where('c.enabled', 1)
            ->where('p.enabled', 1)
            ->whereNull('ch.deleted_at')
            ->field('ch.*')
            ->order('ch.id')
            ->select()
            ->all();
    }

    /** @return array<string,PaymentChannelRevision> 服务只读装配，秘密快照不直接返回前端。 */
    public function revisionsForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $result = [];
        foreach (
            $this->revisions->whereIn('id', array_values(array_unique($ids)))->select()
            as $row
        ) {
            $result[$row['id']] = $row;
        }
        return $result;
    }

    /**
     * 创建默认停用的通道，初始规则使用迁移默认值，不因记录存在即声明可用。
     *
     * @param array{plugin_code:string, api_config_id:string,
     *     name:string, pay_types:string, receipt_scope_key:string} $data pay_types 为已验证的 JSON 数组文本。
     */
    public function create(array $data): string
    {
        return $this->insert($data, [
            'plugin_code',
            'api_config_id',
            'name',
            'pay_types',
            'receipt_scope_key',
        ]);
    }

    /**
     * 配置事务中在对应 PaymentPluginConf 之后锁定通道，保护配置引用及当前版本指针。
     *
     * @throws RuntimeException 通道不存在。
     */
    public function lock(string $id): PaymentChannel
    {
        $this->requireTransaction();
        return $this->model->where('id', $id)->lock(true)->find() ??
            throw new RuntimeException('通道不存在');
    }

    /** 消息入口只据此定位配置锁；进入事务后仍须重新核验真实归属。 */
    public function find(string $id): ?PaymentChannel
    {
        return $this->model->find($id);
    }

    /**
     * @param list<string> $ids 只批量读取候选；选路仍须按稳定顺序加锁并复核。
     * @return list<PaymentChannel>
     */
    public function forIds(array $ids): array
    {
        return $ids === []
            ? []
            : $this->model->whereIn('id', array_values(array_unique($ids)))->select()->all();
    }

    /**
     * @param array{configuration_id?:string,enabled?:int} $filters 配置筛选只影响列表，不改变通道归属。
     * @return array{items:list<PaymentChannel>,total:int}
     */
    public function adminPage(array $filters, int $page, int $limit): array
    {
        $query = $this->model->whereNull('deleted_at');
        if (isset($filters['configuration_id'])) {
            $query->where('api_config_id', $filters['configuration_id']);
        }
        if (array_key_exists('enabled', $filters)) {
            $query->where('enabled', $filters['enabled']);
        }
        return [
            'total' => (clone $query)->count(),
            'items' => $query
                ->field(
                    'id,name,api_config_id,plugin_code,receipt_scope_key,pay_types,enabled,current_revision_id,updated_at',
                )
                ->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->all(),
        ];
    }

    /**
     * 配置管理先锁配置，再按主键锁全部关联通道；单商户锁在更外层，避免与并发建单争用顺序不同。
     *
     * @return list<PaymentChannel>
     */
    public function lockForConfiguration(string $configurationId): array
    {
        $this->requireTransaction();
        return $this->model
            ->where('api_config_id', $configurationId)
            ->order('id')
            ->lock(true)
            ->select()
            ->all();
    }

    /** 活动配置变更使新单准入失效；保留原快照指针供后台重冻结，历史支付单引用不动。 */
    public function disableForConfiguration(string $configurationId, string $now): void
    {
        $this->requireTransaction();
        $this->model
            ->where('api_config_id', $configurationId)
            ->update(['enabled' => 0, 'updated_at' => $now]);
    }

    /**
     * 保存已由服务校验的完整规则，并停止新单直到显式启用；不更改账号归属或旧快照正文。
     *
     * @param array<string,int|string|null> $rules PaymentChannelInput 的白名单记录。
     */
    public function saveRules(string $id, array $rules, string $now): void
    {
        $this->requireTransaction();
        if (
            array_diff(array_keys($rules), [
                'name',
                'receipt_scope_key',
                'pay_types',
                'min_amount',
                'max_amount',
                'daily_limit_amount',
                'daily_limit_count',
                'available_from',
                'available_to',
            ]) !== []
        ) {
            throw new InvalidArgumentException('通道规则包含未知字段');
        }
        $this->model->where('id', $id)->update($rules + ['enabled' => 0, 'updated_at' => $now]);
    }

    /** 调用方已核对待付款订单、轮询组及目标配置；切换引用不改写旧订单的快照。 */
    public function changeConfiguration(
        string $id,
        string $configurationId,
        string $pluginCode,
    ): void {
        $this->requireTransaction();
        $this->model->where('id', $id)->update([
            'api_config_id' => $configurationId,
            'plugin_code' => $pluginCode,
            'enabled' => 0,
        ]);
    }

    /** 服务已核验快照、材料与新单准入；单纯停用不停止同账号历史事实的采集。 */
    public function setEnabled(string $id, bool $enabled, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('id', $id)->update(['enabled' => (int) $enabled, 'updated_at' => $now]);
    }

    /** 删除检查在配置/通道锁内执行；并发建单须先取得同一通道锁，不能绕过此时点。 */
    public function hasPendingPayments(string $id): bool
    {
        $this->requireTransaction();
        return $this->payments
            ->where('channel_id', $id)
            ->whereIn('status', [
                TradeConstant::ORDER_STATUS_CREATED,
                TradeConstant::ORDER_STATUS_PAYING,
            ])
            ->value('id') !== null;
    }

    /** 删除标记和停用同时写入；保留被历史订单引用的实体及全部版本。 */
    public function markDeleted(string $id, string $now): void
    {
        $this->requireTransaction();
        $this->model
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'enabled' => 0, 'updated_at' => $now]);
    }

    /** @return list<array{id:string,name:string}> 编辑共享参数前用于展示影响范围。 */
    public function activeForConfiguration(string $id): array
    {
        return $this->model
            ->where('api_config_id', $id)
            ->whereNull('deleted_at')
            ->field('id,name')
            ->order('id')
            ->select()
            ->toArray();
    }

    /**
     * 插件配置先锁，通道后锁；此配置事务不同时持有交易锁。
     * $channel 为已锁定且验证归属的通道模型，版本快照只追加保存。
     *
     * @param int $configVersion 此快照冻结的活动配置版本，不是通道 revision 序号。
     * @param string $value 服务层已验证并编码的完整 JSON 快照。
     * @return numeric-string 新快照的数据库 ID。
     */
    public function appendRevision(
        PaymentChannel $channel,
        int $configVersion,
        string $value,
    ): string {
        $this->requireTransaction();
        $version = (int) $this->revisions->where('channel_id', $channel['id'])->max('revision') + 1;
        $id = $this->insert(
            [
                'channel_id' => $channel['id'],
                'revision' => $version,
                'api_config_id' => $channel['api_config_id'],
                'config_version' => $configVersion,
                'snapshot_json' => $value,
            ],
            [
                'channel_id',
                'revision',
                'api_config_id',
                'config_version',
                'snapshot_json',
            ],
            model: $this->revisions,
        );
        // 插入快照和更新当前指针必须在同一事务内完成，避免指向未提交或不存在的版本。
        $this->model
            ->where('id', $channel['id'])
            ->update(['current_revision_id' => $id, 'updated_at' => date('Y-m-d H:i:s')]);
        return $id;
    }

    /**
     * 双重限定快照所属通道与版本 ID；未找到时返回 null，不静默取通道最新版本。
     * 建单已经锁定通道的新指针时使用当前读，避免 RR 旧快照看不到刚提交的版本行。
     *
     * @param bool $currentRead true 要求外层事务及通道行锁，false 用于普通历史读取。
     */
    public function revision(
        string $channelId,
        string $revisionId,
        bool $currentRead = false,
    ): ?PaymentChannelRevision {
        $query = $this->revisions->where('channel_id', $channelId)->where('id', $revisionId);
        if ($currentRead) {
            $this->requireTransaction();
            $query->lock(true);
        }
        return $query->find();
    }
}
