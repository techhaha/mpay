<?php
declare(strict_types=1);

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\common\constant\TradeConstant;
use app\model\payment\PaymentPlugin;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPluginConf;
use app\model\payment\ReceiptRuntimeState;
use app\model\payment\PayOrder;

/** @extends BaseRepository<PaymentPlugin> 插件目录查询/持久化；不加载 PHP 文件或决定停用业务策略。 */
final class PaymentPluginRepository extends BaseRepository
{
    public function __construct(PaymentPlugin $model, private readonly PaymentChannel $channels,
        private readonly PaymentPluginConf $configurations, private readonly ReceiptRuntimeState $runtime,
        private readonly PayOrder $orders) { parent::__construct($model); }

    /** @return list<PaymentPlugin> 包含代码已经移除的目录记录，管理页应明确显示未安装。 */
    public function all(): array { return $this->model->order('id')->select()->all(); }

    public function find(string $code, bool $lock = false): ?PaymentPlugin
    {
        $query = $this->model->where('code', $code);
        if ($lock) { $this->requireTransaction(); $query->lock(true); }
        return $query->find();
    }

    public function channelCount(string $code): int
    {
        return $this->channels->where('plugin_code', $code)->whereNull('deleted_at')->count();
    }

    /** 当前订单责任按配置限定；用于服务判断停用插件是否还需要完成存量采集。 */
    public function hasPending(string $configurationId): bool
    {
        return $this->orders->alias('p')->join('ma_payment_channel c', 'c.id=p.channel_id')
            ->where('c.api_config_id', $configurationId)
            ->whereIn('p.status', [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING])->value('p.id') !== null;
    }

    /** 元信息完整同步；仅写定义，不改人工启停。无变化刷新不递增版本。 */
    public function synchronize(array $definition): void
    {
        $this->requireTransaction();
        $fields = ['name','version','pay_types','class_name','config_schema','description'];
        $row = $this->find($definition['code'], true);
        $values = array_intersect_key($definition, array_flip($fields));
        if ($row !== null) {
            $changed = false;
            foreach ($values as $field => $value) {
                $same = is_array($value)
                    ? \app\common\validation\PluginConfigValidator::sameSchema($value, $row[$field])
                    : $row[$field] === $value;
                if (!$same) { $changed = true; break; }
            }
            if (!$changed) { return; }
        }
        foreach (['pay_types','config_schema'] as $field) {
            $values[$field] = json_encode($values[$field], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if ($row === null) { $this->insert(['code' => $definition['code']] + $values, ['code', ...$fields]); }
        else { $this->model->where('id', $row['id'])->update($values + ['revision' => (int) $row['revision'] + 1]); }
    }

    /** 清理已移除且没有任何业务引用的插件定义；有关联的缺失插件留供管理页定位问题。 */
    public function removeUnusedMissing(array $installed): void
    {
        $this->requireTransaction();
        foreach ($this->all() as $row) {
            if (in_array($row['code'], $installed, true)
                || $this->channels->where('plugin_code', $row['code'])->count() > 0
                || $this->configurations->where('plugin_code', $row['code'])->count() > 0
                || $this->orders->where('plugin_code', $row['code'])->count() > 0) { continue; }
            $this->model->where('id', $row['id'])->delete();
        }
    }

    /** 调用服务已锁定目录并完成版本校验；唤醒等待记录，不撤销正在执行的存量租约。 */
    public function setEnabled(PaymentPlugin $plugin, bool $enabled, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('id', $plugin['id'])->where('revision', $plugin['revision'])
            ->update(['enabled' => (int) $enabled, 'revision' => (int) $plugin['revision'] + 1, 'updated_at' => $now]);
        $ids = $this->configurations->where('plugin_code', $plugin['code'])->whereNotNull('query_key')->column('query_key');
        if ($ids !== []) { $this->runtime->whereIn('query_key', $ids)->whereNull('claim_token')->update(['next_run_at' => $now]); }
    }
}
