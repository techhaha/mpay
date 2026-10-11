<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\interface\ReceiptCollectorInterface;
use app\common\util\Clock;
use app\common\validation\PluginConfigValidator;
use app\exception\BusinessException;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\repository\payment\runtime\ReceiptRuntimeStateRepository;
use app\repository\payment\runtime\ReceiptSessionRepository;
use app\service\file\FileAssetService;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\plugin\PluginDefinition;
use app\service\payment\receipt\ReceiptScopeService;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\audit\ConfigurationAuditService;
use LogicException;
use RuntimeException;
use SensitiveParameter;

/** 通道专属插件参数及不可变版本管理；插件定义采集身份，本服务保存关联与快照，不猜平台字段。 */
final class PaymentPluginConfService extends BaseService
{
    public function __construct(private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentChannelRepository $channels,
        private readonly ReceiptScopeRepository $scopes, private readonly PaymentCatalogRepository $catalog,
        private readonly PaymentPluginFactoryService $plugins, private readonly PluginConfigValidator $validator,
        private readonly ReceiptRuntimeStateRepository $runtime, private readonly ReceiptSessionRepository $sessions,
        private readonly ConfigurationAuditService $audit, private readonly Clock $clock, private readonly PaymentCatalogService $management,
        private readonly FileAssetService $files, private readonly ReceiptScopeService $scopeService) {}

    /**
     * 在通道统一创建事务内保存插件参数，不独立提交或执行外部请求。
     * @param array<string,mixed> $settings
     * @return array{id:string,config_version:int,enabled:bool}
     */
    public function createInTransaction(string $code, string $name, #[SensitiveParameter] array $settings, ConfigurationActionContext $actor): array
    {
        $this->requireTransaction();
        $this->management->assertNew($code);
        $definition = $this->plugins->definition($code);
        $settings = $this->validator->validate($definition->configSchema, $settings, true);
        $this->validateAssets($definition, $settings);
        $this->audit->authorize($actor);
        $id = $this->configurations->create(['plugin_code' => $definition->code, 'name' => $name,
            'config_json' => json_encode((object) $settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        $identity = $this->identity($definition->code, $settings, $id);
        $this->configurations->saveIdentity($id, $identity);
        $this->prepareCollection($identity);
        // 插件给出采集流水身份；手机插件没有真实账号声明时保持通道专属范围。
        $keys = []; $types = $definition->payTypes; sort($types, SORT_STRING);
        foreach ($types as $type) { $keys[] = $this->scopeService->registerInTransaction($type, $identity['account_key']); }
        sort($keys, SORT_STRING);
        $this->configurations->appendRevision($this->configurations->lock($id), $definition->version, $keys);
        $this->audit->record($actor, 'configuration.create', 'payment_plugin_conf', $id, 'created',
            ['plugin_code' => $definition->code, 'plugin_version' => $definition->version, 'new_version' => 1]);
        return ['id' => $id, 'config_version' => 1, 'enabled' => false];
    }

    /**
     * 组合编辑保存完整插件配置，并复核版本与范围；调用方负责唯一外层事务。
     * 参数、插件版本或采集身份改变时创建新快照并停用当前通道；存量快照不改写。
     * @param array<string,mixed> $settings
     * @return array{id:string,config_version:int,enabled:bool}
     */
    public function saveInTransaction(
        string $id,
        int $expectedVersion,
        string $name,
        #[SensitiveParameter] array $settings,
        ConfigurationActionContext $actor
    ): array {
        $this->requireTransaction();
        $this->audit->authorize($actor);
        $configuration = $this->configurations->lock($id);
        $this->assertVersion($configuration['config_version'], $expectedVersion);
        $this->channels->lockForConfiguration($id);
        $definition = $this->plugins->definition($configuration['plugin_code']);
        $old = $configuration['config_json'];
        if (!is_array($old)) { throw new RuntimeException('原配置正文无效'); }
        $settings = $this->validator->validate($definition->configSchema, $settings, true);
        $this->validateAssets($definition, $settings);
        $identity = $this->identity($definition->code, $settings, $id);
        if ($identity['account_key'] !== $configuration['account_key'] || $identity['query_key'] !== $configuration['query_key']) {
            foreach ($this->channels->activeForConfiguration($id) as $bound) {
                if ($this->channels->hasPendingPayments($bound['id'])) { throw new BusinessException('通道仍有待付款订单，暂不能更换收款账号', ApiResponseConstant::CONFLICT); }
            }
        }
        $keys = []; $types = $definition->payTypes; sort($types, SORT_STRING);
        foreach ($types as $type) { $keys[] = $this->scopeService->registerInTransaction($type, $identity['account_key']); }
        sort($keys, SORT_STRING);
        $this->lockScopes($keys, $definition);
        $revision = $this->configurations->revision($id, $expectedVersion)
            ?? throw new RuntimeException('插件配置版本缺失');
        $sameIdentity = true;
        foreach (['account_key', 'query_key', 'session_key', 'auth_fingerprint'] as $field) {
            if ($identity[$field] !== $configuration[$field]) { $sameIdentity = false; }
        }
        $currentKeys = array_keys($this->scopeService->current($configuration, true));
        // 升级后即使表单值相同，也必须重新登记接口身份和范围；旧订单继续使用原快照。
        if ($name === $configuration['name'] && PluginConfigValidator::sameSchema($settings, $old)
            && $sameIdentity && $revision['plugin_version'] === $definition->version && $currentKeys === $keys) {
            $this->prepareCollection($identity);
            return ['id' => $id, 'config_version' => $expectedVersion, 'enabled' => (int) $configuration['enabled'] === 1];
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $version = $this->configurations->replace($configuration, $name, json_encode((object) $settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $definition->version, $keys, $now);
        $this->channels->disableForConfiguration($id, $now);
        $this->configurations->saveIdentity($id, $identity);
        $this->prepareCollection($identity);
        // 新快照改用本次插件返回的范围；旧快照及旧单不改写。
        foreach ($this->channels->lockForConfiguration($id) as $channel) {
            foreach ($keys as $key) {
                if ($this->catalog->typeCode($this->scopes->find($key)['pay_type_id']) === $channel['pay_types'][0]) {
                    $this->channels->changeScope($channel['id'], $key);
                }
            }
        }
        $this->audit->record($actor, 'configuration.save', 'payment_plugin_conf', $id, 'updated', ['old_version' => $expectedVersion, 'new_version' => $version]);
        return ['id' => $id, 'config_version' => $version, 'enabled' => (int) $configuration['enabled'] === 1];
    }

    /** 通道启用时在同一事务里复核并启用采集授权，不允许只完成其中一项。 */
    public function setEnabledInTransaction(string $id, int $expectedVersion, bool $enabled, ConfigurationActionContext $actor): void
    {
        $this->requireTransaction();
        $this->audit->authorize($actor);
        $configuration = $this->configurations->lock($id);
        $this->assertVersion($configuration['config_version'], $expectedVersion);
        $this->channels->lockForConfiguration($id);
        $definition = $this->plugins->definition($configuration['plugin_code']);
        $this->scopeService->current($configuration, true);
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        if ($enabled) {
            $this->management->assertNew($configuration['plugin_code']);
            $values = $configuration['config_json'];
            $this->validator->validate($definition->configSchema, $values);
        } else { $this->channels->disableForConfiguration($id, $now); }
        $this->configurations->setEnabled($id, $enabled, $now);
        if ($configuration['query_key'] !== null) { $this->runtime->wake($configuration['query_key'], $now); }
        $this->audit->record($actor, 'configuration.enabled', 'payment_plugin_conf', $id, $enabled ? 'enabled' : 'disabled', ['enabled' => $enabled]);
    }

    /** 插件负责字段语义，服务只验证身份合同；这些摘要不展示给配置表单。 */
    private function identity(string $code, array $settings, string $id): array
    {
        $plugin = $this->plugins->initialize($code, $settings, []);
        if (!$plugin instanceof ReceiptCollectorInterface) {
            return ['account_key'=>hash('sha256', $code . ':configuration:' . $id), 'query_key'=>null, 'session_key'=>null, 'auth_fingerprint'=>null];
        }
        $identity = $plugin->getCollectionIdentity();
        if (array_keys($identity) === []) { throw new LogicException('采集身份声明缺失'); }
        foreach (['account_key','query_key','session_key','auth_fingerprint'] as $field) {
            if (!array_key_exists($field, $identity) || ($field !== 'session_key' || $identity[$field] !== null)
                && (!is_string($identity[$field]) || !preg_match('/^[a-f0-9]{64}$/D', $identity[$field]))) {
                throw new LogicException('插件采集身份须为明确的 SHA-256 摘要');
            }
        }
        return array_intersect_key($identity, array_flip(['account_key', 'query_key', 'session_key', 'auth_fingerprint']));
    }

    /** 配置事务预建查询任务及登录会话，不发网络请求，也不因二维码或有效期编辑清会话。 */
    private function prepareCollection(array $identity): void
    {
        if ($identity['query_key'] === null) { return; }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $state = $this->runtime->lockForQuery($identity['query_key']);
        if ($state === null) { $this->runtime->initialize($identity['query_key'], $now); }
        else { $this->runtime->wake($identity['query_key'], $now); }
        if ($identity['session_key'] !== null) { $this->sessions->ensure($identity['session_key'], $identity['auth_fingerprint']); }
    }

    /** @param list<string> $keys 不执行网络核验，仅检查已登记的支付方式与插件合同。 */
    private function lockScopes(array $keys, PluginDefinition $definition): array
    {
        sort($keys, SORT_STRING); $records = [];
        foreach ($keys as $key) {
            $scope = $this->scopes->lock($key); $records[$key] = $scope;
            if (!in_array($this->catalog->typeCode($scope['pay_type_id']), $definition->payTypes, true)) {
                throw new BusinessException('插件不支持所选范围的支付方式', ApiResponseConstant::VALIDATION_FAILED);
            }
        }
        return $records;
    }

    /** 图片只允许引用已登记资产；字段名称和是否存在由插件 schema 决定。 */
    private function validateAssets(PluginDefinition $definition, array $settings): void
    {
        foreach ($definition->configSchema as $field) {
            if ($field['type'] === 'upload' && ($settings[$field['field']] ?? '') !== '') {
                $this->files->publicImagePath($settings[$field['field']]);
            }
        }
    }

    private function assertVersion(int $current, int $expected): void
    {
        if ($current !== $expected) { throw new BusinessException('插件配置已修改，请刷新', ApiResponseConstant::CONFLICT); }
    }

}
