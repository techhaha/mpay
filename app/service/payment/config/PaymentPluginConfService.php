<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\validation\PluginConfigValidator;
use app\exception\BusinessException;
use app\model\payment\PaymentPluginConf;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\service\file\FileAssetService;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\plugin\PluginDefinition;
use app\service\payment\receipt\ReceiptScopeService;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\audit\ConfigurationAuditService;
use RuntimeException;

/**
 * 通道专属插件参数及版本管理；保存关联与快照，不猜平台字段或维护登录态。
 * 传入模型须来自本事务的锁定读取；Editor 负责鉴权、版本检查和锁序，本类负责参数、材料及范围。
 *
 * @internal 仅供 PaymentChannelEditorService 的组合事务调用，不是独立管理入口。
 */
final class PaymentPluginConfService extends BaseService
{
    public function __construct(
        private readonly PaymentPluginConfRepository $configurations,
        private readonly ReceiptScopeRepository $scopes,
        private readonly PaymentCatalogRepository $catalog,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly PluginConfigValidator $validator,
        private readonly ConfigurationAuditService $audit,
        private readonly FileAssetService $files,
        private readonly ReceiptScopeService $scopeService,
    ) {}

    /**
     * 在通道统一创建事务内保存插件参数，不独立提交或执行外部请求。
     * Editor 已按支付方式 ID 取齐插件支持方式及默认组锁，登记范围只重入已有方式锁。
     * 保存并启用与仅保存使用相同参数检查；新配置在本事务内直接写入目标启用状态。
     * @param array<string,mixed> $settings
     * @return array{id:string,config_version:int,enabled:bool}
     */
    public function createInTransaction(
        string $code,
        string $name,
        array $settings,
        bool $enabled,
        ConfigurationActionContext $actor,
    ): array {
        $this->requireTransaction();
        $definition = $this->plugins->definition($code);
        $settings = $this->validator->validate($definition->configSchema, $settings, true);
        $this->validateAssets($definition, $settings);
        $id = $this->configurations->create([
            'plugin_code' => $definition->code,
            'name' => $name,
            'enabled' => (int) $enabled,
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
                'enabled' => $enabled,
            ],
        );
        return ['id' => $id, 'config_version' => 1, 'enabled' => $enabled];
    }

    /**
     * Editor 已在外层事务鉴权、锁定配置及关联通道并核对版本；复用该模型保存完整参数。
     * 调用方先锁插件支持的全部方式及默认组，禁止在此补取新的方式锁；本方法继续核验参数、材料及范围。
     * 参数或插件版本改变时创建新快照，保持原启停状态；存量快照不改写。
     * @param array<string,mixed> $settings
     * @return array{id:string,config_version:int,enabled:bool}
     */
    public function saveInTransaction(
        PaymentPluginConf $configuration,
        string $name,
        array $settings,
        ConfigurationActionContext $actor,
    ): array {
        $this->requireTransaction();
        $id = $configuration['id'];
        $currentVersion = (int) $configuration['config_version'];
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
            $this->configurations->revision($id, $currentVersion) ??
            throw new RuntimeException('插件配置版本缺失');
        // 原配置快照保留；普通保存不触发登录，也不比较平台账号或凭证。
        if (
            $name === $configuration['name'] &&
            $this->sameSettings($settings, $old) &&
            $revision['plugin_version'] === $definition->version
        ) {
            return [
                'id' => $id,
                'config_version' => $currentVersion,
                'enabled' => (int) $configuration['enabled'] === 1,
            ];
        }
        $now = date('Y-m-d H:i:s');
        $version = $this->configurations->replace(
            $configuration,
            $name,
            json_encode((object) $settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $definition->version,
            $now,
        );
        // 参数修改不改变配置 ID 与支付方式，通道范围沿用原关联；接入切换由 Editor 保存通道规则。
        $this->audit->record($actor, 'configuration.save', 'payment_plugin_conf', $id, 'updated', [
            'old_version' => $currentVersion,
            'new_version' => $version,
        ]);
        return [
            'id' => $id,
            'config_version' => $version,
            'enabled' => (int) $configuration['enabled'] === 1,
        ];
    }

    /** Editor 已锁定配置/通道并核对版本和插件开关；这里检查实际参数及材料后启用配置。 */
    public function enableInTransaction(
        PaymentPluginConf $configuration,
        ConfigurationActionContext $actor,
    ): void {
        $this->requireTransaction();
        $definition = $this->plugins->definition($configuration['plugin_code']);
        $now = date('Y-m-d H:i:s');
        $this->validator->validate($definition->configSchema, $configuration['config_json']);
        $this->validateAssets($definition, $configuration['config_json']);
        $this->configurations->setEnabled($configuration['id'], true, $now);
        // 同事务的通道启用继续使用这份已锁定模型，无需重新查询配置。
        $configuration['enabled'] = 1;
        $this->audit->record(
            $actor,
            'configuration.enabled',
            'payment_plugin_conf',
            $configuration['id'],
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

    /** 当前条件下使用的上传项须引用已登记资产；隐藏项保留配置值，不读取文件。 */
    private function validateAssets(PluginDefinition $definition, array $settings): void
    {
        foreach ($definition->configSchema as $field) {
            if (
                $field['type'] === 'upload' &&
                PluginConfigValidator::isVisible($field, $settings) &&
                ($settings[$field['field']] ?? '') !== ''
            ) {
                $this->files->publicImagePath($settings[$field['field']]);
            }
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
