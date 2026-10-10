<?php
declare(strict_types=1);

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentPluginConfRevision;

/**
 * 保存活动收款账号配置及其版本号；配置由服务验证后按 JSON 写入，旧单配置由通道快照保留。
 *
 * @extends BaseRepository<PaymentPluginConf>
 */
final class PaymentPluginConfRepository extends BaseRepository
{
    public function __construct(PaymentPluginConf $model, private readonly PaymentPluginConfRevision $revisions,
        private readonly PaymentPluginConfScopeRepository $bindings)
    {
        parent::__construct($model);
    }

    /**
     * 创建默认停用的收款配置；调用方在同一事务内追加首个版本及范围绑定后才能提交。
     * config_json 包含秘密，列表查询必须使用公开字段白名单。
     *
     * @param array{plugin_code:string, name:string,
     *     config_json:string} $data
     */
    public function create(array $data): string
    {
        return $this->insert($data, ['plugin_code', 'name', 'config_json']);
    }

    /**
     * 配置变更的锁入口；涉及通道快照时先锁本配置，再锁通道，保持配置事务锁序一致。
     *
     * @throws \RuntimeException 配置不存在。
     */
    public function lock(string $id): PaymentPluginConf
    {
        $this->requireTransaction();
        return $this->model->where('id', $id)->lock(true)->find() ?? throw new \RuntimeException('收款配置不存在');
    }

    /** @return PaymentPluginConf|null 无锁展示/定位；修改必须使用 lock 复核。 */
    public function find(string $id): ?PaymentPluginConf
    {
        return $this->model->find($id);
    }

    /** 只保存插件返回的内部身份，不解释或比较平台字段；调用方已锁配置。 */
    public function saveIdentity(string $id, array $identity): void
    {
        $this->requireTransaction();
        $this->model->where('id', $id)->update(array_intersect_key($identity, array_flip(['account_key','query_key','session_key','auth_fingerprint'])));
    }

    /** 同一查询任务的配置集合；按 ID 锁定并冻结版本后才可外呼。 */
    public function collectionGroup(string $queryKey, bool $lock = false): array
    {
        $query = $this->model->alias('c')->where('c.query_key', $queryKey)->where('c.enabled', 1)->order('c.id');
        $this->collectionEligibility($query);
        if ($lock) { $this->requireTransaction(); $query->lock(true); }
        return $query->select()->all();
    }

    /** 登录会话可被不同门店查询共享；检查同一登录身份是否提交互相冲突的凭证。 */
    public function sessionFingerprints(string $key): array
    {
        $query = $this->model->alias('c')->where('c.session_key', $key)->where('c.enabled', 1);
        $this->collectionEligibility($query);
        return array_values(array_unique($query->column('c.auth_fingerprint')));
    }

    /** 有启用通道或未结支付责任才参加采集；停用且无在途订单的重复配置不再占用登录会话。 */
    private function collectionEligibility(\think\db\Query $query): void
    {
        $query->whereExists(function (\think\db\Query $channels): void {
            $channels->table('ma_payment_channel')->alias('ch')->field('ch.id')->whereColumn('ch.api_config_id', 'c.id')
                ->where(function (\think\db\Query $available): void {
                    $available->where(function (\think\db\Query $enabled): void { $enabled->where('ch.enabled', 1)->whereNull('ch.deleted_at'); })
                        ->whereExists(function (\think\db\Query $pending): void {
                            $pending->table('ma_pay_order')->alias('po')->field('po.id')->whereColumn('po.channel_id', 'ch.id')
                                ->whereIn('po.status', [\app\common\constant\TradeConstant::ORDER_STATUS_CREATED, \app\common\constant\TradeConstant::ORDER_STATUS_PAYING]);
                        }, 'OR');
                });
        });
    }

    /**
     * 配置行锁之后读取不可变版本；null 明确表示缺失，不能回退活动配置伪造历史。
     *
     * @param bool $currentRead 当前版本指针已锁定时用当前读，避免 RR 旧快照。
     */
    public function revision(string $id, int $version, bool $currentRead = false): ?PaymentPluginConfRevision
    {
        $query = $this->revisions->where('api_config_id', $id)->where('config_version', $version);
        if ($currentRead) { $this->requireTransaction(); $query->lock(true); }
        return $query->find();
    }

    /**
     * 追加已锁定配置的当前版本和完整绑定集合。仅服务校验完成后调用，不接受秘密默认值补齐。
     *
     * @param list<string> $scopeKeys 服务已核验真实范围并按键排序，至少一个范围。
     */
    public function appendRevision(PaymentPluginConf $configuration, string $pluginVersion, array $scopeKeys): void
    {
        $this->requireTransaction();
        if ($scopeKeys === [] || !array_is_list($scopeKeys) || count(array_unique($scopeKeys)) !== count($scopeKeys)) {
            throw new \InvalidArgumentException('配置版本需要明确且不重复的授权范围');
        }
        $this->insert(['api_config_id' => $configuration['id'], 'config_version' => (int) $configuration['config_version'],
            'plugin_version' => $pluginVersion, 'config_json' => json_encode((object) $configuration['config_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['api_config_id', 'config_version', 'plugin_version', 'config_json'], model: $this->revisions);
        $this->bindings->append($configuration['id'], (int) $configuration['config_version'], $scopeKeys);
    }

    /**
     * 调用方持有配置、相关通道/范围锁；活动行和新版本同事务写入，旧正文/绑定不修改。
     *
     * @param list<string> $scopeKeys 已完成归属、保护窗口及插件能力核验的完整新集合。
     * @return int 新配置逻辑版本号，原通道快照需显式重新冻结才可用于新单。
     */
    public function replace(PaymentPluginConf $configuration, string $name, string $json, string $pluginVersion,
        array $scopeKeys, string $now): int
    {
        $this->requireTransaction();
        $version = (int) $configuration['config_version'];
        if ($version >= 4294967295) { throw new \RuntimeException('配置版本号已用尽'); }
        $updated = $this->model->where('id', $configuration['id'])->where('config_version', $version)
            ->update(['name' => $name, 'config_json' => $json, 'config_version' => $version + 1, 'updated_at' => $now]);
        if ($updated !== 1) { throw new \RuntimeException('配置当前版本已改变'); }
        $this->appendRevision($this->lock($configuration['id']), $pluginVersion, $scopeKeys);
        return $version + 1;
    }

    /** 服务核对配置版本及插件字段后调用；开关本身不证明平台或设备健康。 */
    public function setEnabled(string $id, bool $enabled, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('id', $id)->update(['enabled' => (int) $enabled, 'updated_at' => $now]);
    }

    /**
     * 联表只筛选到期账号 ID，不在扫描中持锁或读取秘密；认领服务必须按当前配置再核验。
     * 只选择已有启用通道或未结订单的主动采集配置；查询运行记录应由配置事务预建。
     *
     * @return list<string>
     */
    public function dueCollectionIds(string $now, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('采集候选批量超出范围');
        }
        $query = $this->model->alias('c')->leftJoin(['ma_receipt_runtime_state' => 'r'], 'r.query_key=c.query_key');
        $this->collectionEligibility($query);
        $ids = $query
            ->where('c.enabled', 1)->whereNotNull('c.query_key')->where(function (\think\db\Query $query) use ($now): void {
                $query->whereNull('r.id')->whereOr('r.next_run_at', '<=', $now);
            })->where(function (\think\db\Query $query) use ($now): void {
                $query->whereNull('r.lease_until')->whereOr('r.lease_until', '<=', $now);
            })->order('r.next_run_at,c.id')->limit($limit)->column('c.id');
        return array_map('strval', $ids);
    }
}
