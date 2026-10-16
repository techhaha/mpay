<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\util\Clock;
use app\common\validation\PluginConfigValidator;
use app\exception\BusinessException;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\service\file\FileAssetService;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\plugin\PluginDefinition;
use app\service\payment\receipt\ReceiptScopeService;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\audit\ConfigurationAuditService;
use RuntimeException;

/** 通道专属插件参数及版本管理；Editor 在外层事务统一鉴权，本服务保存关联与快照，不猜平台字段或维护登录态。 */
final class PaymentPluginConfService extends BaseService
{
    public function __construct(
        private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentChannelRepository $channels,
        private readonly ReceiptScopeRepository $scopes,
        private readonly PaymentCatalogRepository $catalog,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly PluginConfigValidator $validator,
        private readonly ConfigurationAuditService $audit,
        private readonly Clock $clock,
        private readonly PaymentCatalogService $management,
        private readonly FileAssetService $files,
        private readonly ReceiptScopeService $scopeService,
    ) {}

    /**
     * 在通道统一创建事务内保存插件参数，不独立提交或执行外部请求。
     * Editor 已按支付方式 ID 取齐插件支持方式及默认组锁，登记范围只重入已有方式锁。
     * @param array<string,mixed> $settings
     * @return array{id:string,config_version:int,enabled:bool}
     */
    public function createInTransaction(
        string $code,
        string $name,
        array $settings,
        ConfigurationActionContext $actor,
    ): array {
        $this->requireTransaction();
        $this->management->assertNew($code);
        $definition = $this->plugins->definition($code);
        $settings = $this->validator->validate($definition->configSchema, $settings, true);
        $this->validateAssets($definition, $settings);
        $id = $this->configurations->create([
            'plugin_code' => $definition->code,
            'name' => $name,
            'config_json' => json_encode(
                (object) $settings,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        ]);
        // 业务锁按配置和支付方式登记，账号归并只在插件采集时进行。
        $types = $definition->payTypes;
        sort($types, SORT_STRING);
        foreach ($types as $type) {
            $this->scopeService->registerInTransaction($type, $id);
        }
        $this->configurations->appendRevision(
            $this->configurations->lock($id),
            $definition->version,
        );
        $this->audit->record(
            $actor,
            'configuration.create',
            'payment_plugin_conf',
            $id,
            'created',
            [
                'plugin_code' => $definition->code,
                'plugin_version' => $definition->version,
                'new_version' => 1,
            ],
        );
        return ['id' => $id, 'config_version' => 1, 'enabled' => false];
    }

    /**
     * 组合编辑保存完整插件配置，并复核版本与范围；调用方负责唯一外层事务。
     * 调用方先锁插件支持的全部方式及默认组，再锁当前配置和通道，禁止在此补取新的方式锁。
     * 参数或插件版本改变时创建新快照并停用当前通道；存量快照不改写。
     * @param array<string,mixed> $settings
     * @return array{id:string,config_version:int,enabled:bool}
     */
    public function saveInTransaction(
        string $id,
        int $expectedVersion,
        string $name,
        array $settings,
        ConfigurationActionContext $actor,
    ): array {
        $this->requireTransaction();
        $configuration = $this->configurations->lock($id);
        $this->assertVersion($configuration['config_version'], $expectedVersion);
        $this->channels->lockForConfiguration($id);
        $definition = $this->plugins->definition($configuration['plugin_code']);
        $old = $configuration['config_json'];
        if (!is_array($old)) {
            throw new RuntimeException('原配置正文无效');
        }
        $settings = $this->validator->validate($definition->configSchema, $settings, true);
        $this->validateAssets($definition, $settings);
        $keys = [];
        $types = $definition->payTypes;
        sort($types, SORT_STRING);
        foreach ($types as $type) {
            $keys[] = $this->scopeService->registerInTransaction($type, $id);
        }
        $this->lockScopes($keys, $definition);
        $revision =
            $this->configurations->revision($id, $expectedVersion) ??
            throw new RuntimeException('插件配置版本缺失');
        // 原配置快照保留；普通保存不触发登录，也不比较平台账号或凭证。
        if (
            $name === $configuration['name'] &&
            $this->sameSettings($settings, $old) &&
            $revision['plugin_version'] === $definition->version
        ) {
            return [
                'id' => $id,
                'config_version' => $expectedVersion,
                'enabled' => (int) $configuration['enabled'] === 1,
            ];
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $version = $this->configurations->replace(
            $configuration,
            $name,
            json_encode((object) $settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $definition->version,
            $now,
        );
        $this->channels->disableForConfiguration($id, $now);
        // 参数修改不改变配置 ID 与支付方式，通道范围沿用原关联；接入切换由 Editor 保存通道规则。
        $this->audit->record($actor, 'configuration.save', 'payment_plugin_conf', $id, 'updated', [
            'old_version' => $expectedVersion,
            'new_version' => $version,
        ]);
        return [
            'id' => $id,
            'config_version' => $version,
            'enabled' => (int) $configuration['enabled'] === 1,
        ];
    }

    /** Editor 已在事务入口鉴权；这里复核并启用参数，通道停用仍由通道服务处理。 */
    public function enableInTransaction(
        string $id,
        int $expectedVersion,
        ConfigurationActionContext $actor,
    ): void {
        $this->requireTransaction();
        $configuration = $this->configurations->lock($id);
        $this->assertVersion($configuration['config_version'], $expectedVersion);
        $this->channels->lockForConfiguration($id);
        $definition = $this->plugins->definition($configuration['plugin_code']);
        $this->scopeService->current($configuration, true);
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->management->assertNew($configuration['plugin_code']);
        $this->validator->validate($definition->configSchema, $configuration['config_json']);
        $this->configurations->setEnabled($id, true, $now);
        $this->audit->record(
            $actor,
            'configuration.enabled',
            'payment_plugin_conf',
            $id,
            'enabled',
            ['enabled' => true],
        );
    }

    /** @param list<string> $keys 不执行网络核验，仅检查已登记的支付方式与插件合同。 */
    private function lockScopes(array $keys, PluginDefinition $definition): void
    {
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $scope = $this->scopes->lock($key);
            if (
                !in_array(
                    $this->catalog->typeCode($scope['pay_type_id']),
                    $definition->payTypes,
                    true,
                )
            ) {
                throw new BusinessException(
                    '插件不支持所选范围的支付方式',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
        }
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
        if ($current !== $expected) {
            throw new BusinessException(
                '插件配置已修改，请刷新',
                ApiResponseConstant::STALE_VERSION,
            );
        }
    }

    /** 配置对象键顺序不构成修改；字段值严格比较，列表的顺序仍有意义。 */
    private function sameSettings(array $expected, array $stored): bool
    {
        ksort($expected);
        ksort($stored);
        return $expected === $stored;
    }
}
