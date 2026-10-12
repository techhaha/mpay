<?php
declare(strict_types=1);

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentPluginConfRevision;
use RuntimeException;

/** @extends BaseRepository<PaymentPluginConf> 通道专属参数及不可变版本，不登记平台登录或采集任务。 */
final class PaymentPluginConfRepository extends BaseRepository
{
    public function __construct(PaymentPluginConf $model, private readonly PaymentPluginConfRevision $revisions)
    {
        parent::__construct($model);
    }

    /** config_json 含凭证，读取后只用于插件调用或已认证的配置表单。 */
    public function create(array $data): string
    {
        return $this->insert($data, ['plugin_code', 'name', 'config_json']);
    }

    /** 配置事务先锁配置再锁通道，禁止网络请求进入此事务。 */
    public function lock(string $id): PaymentPluginConf
    {
        $this->requireTransaction();
        return $this->model->where('id', $id)->lock(true)->find() ?? throw new RuntimeException('收款配置不存在');
    }

    public function find(string $id): ?PaymentPluginConf { return $this->model->find($id); }

    /** @return array<string,PaymentPluginConf> 批量装配，避免每个订单重复读取凭证。 */
    public function forIds(array $ids): array
    {
        if ($ids === []) { return []; }
        $result = [];
        foreach ($this->model->whereIn('id', array_values(array_unique($ids)))->select() as $row) { $result[$row['id']] = $row; }
        return $result;
    }

    /** 缺失版本明确返回 null，不以当前配置补造原单快照。 */
    public function revision(string $id, int $version, bool $currentRead = false): ?PaymentPluginConfRevision
    {
        $query = $this->revisions->where('api_config_id', $id)->where('config_version', $version);
        if ($currentRead) { $this->requireTransaction(); $query->lock(true); }
        return $query->find();
    }

    /** 调用方已验证字段；只追加版本，不改写已有订单引用的正文。 */
    public function appendRevision(PaymentPluginConf $configuration, string $pluginVersion): void
    {
        $this->requireTransaction();
        $this->insert(['api_config_id' => $configuration['id'], 'config_version' => (int) $configuration['config_version'],
            'plugin_version' => $pluginVersion, 'config_json' => json_encode((object) $configuration['config_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            ['api_config_id', 'config_version', 'plugin_version', 'config_json'], model: $this->revisions);
    }

    /** 活动参数与新版本在调用方事务中一并提交，旧版本不变化。 */
    public function replace(PaymentPluginConf $configuration, string $name, string $json, string $pluginVersion, string $now): int
    {
        $this->requireTransaction();
        $version = (int) $configuration['config_version'];
        if ($version >= 4294967295) { throw new RuntimeException('配置版本号已用尽'); }
        $updated = $this->model->where('id', $configuration['id'])->where('config_version', $version)
            ->update(['name' => $name, 'config_json' => $json, 'config_version' => $version + 1, 'updated_at' => $now]);
        if ($updated !== 1) { throw new RuntimeException('配置当前版本已改变'); }
        $this->appendRevision($this->lock($configuration['id']), $pluginVersion);
        return $version + 1;
    }

    public function setEnabled(string $id, bool $enabled, string $now): void
    {
        $this->requireTransaction();
        $this->model->where('id', $id)->update(['enabled' => (int) $enabled, 'updated_at' => $now]);
    }
}
