<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\model\payment\PaymentPluginConf;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\receipt\ReceiptScopeService;
use app\service\payment\routing\DefaultRoutingService;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\audit\ConfigurationAuditService;

/**
 * 正式通道管理：完整规则、配置授权、冻结快照与审计一起提交；新通道和修改后的通道保持停用。
 * 启用只开放新单；停用不关闭静态二维码，也不停止原账号的历史到账采集。
 * 仅由 Editor 在已授权的外层事务内调用；这里核对配置及通道版本，不重复查询管理员。
 */
final class PaymentChannelService extends BaseService
{
    public function __construct(private readonly ConfigurationAuditService $audit,
        private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentChannelRepository $channels, private readonly PaymentChannelRevisionService $revisions,
        private readonly PaymentCatalogRepository $catalog, private readonly PaymentPluginFactoryService $plugins,
        private readonly ReceiptScopeService $scopeAccess,
        private readonly Clock $clock, private readonly PaymentCatalogService $management,
        private readonly DefaultRoutingService $defaults)
    {
    }

    /**
     * 组合创建在外层事务内调用；插件字段与图片引用由插件配置服务检查。
     * @return array{id:string,revision_id:string,enabled:bool}
     */
    public function writeInTransaction(?string $id, ?string $expectedRevision, string $configurationId, int $expectedConfigurationVersion,
        PaymentChannelInput $input, ConfigurationActionContext $actor): array
    {
        $this->requireTransaction();
        $old = $id === null ? null : $this->channels->find($id);
        $this->defaults->lockTypes(array_unique([...($old['pay_types'] ?? []), $input->payType]));

        $configuration = $this->configurations->lock($configurationId);
        $this->assertVersion($configuration, $expectedConfigurationVersion);
        foreach ($this->channels->activeForConfiguration($configurationId) as $bound) {
            if ($bound['id'] !== $id) { throw new BusinessException('插件配置已经属于其他通道', ApiResponseConstant::CONFLICT); }
        }
        if ($id !== null) {
            $channel = $this->channels->lock($id);
            if ($channel['deleted_at'] !== null) { throw new BusinessException('通道已删除', ApiResponseConstant::NOT_FOUND); }
            if ($channel['api_config_id'] !== $configurationId || $channel['current_revision_id'] !== $expectedRevision) {
                throw new BusinessException('通道已改变，请刷新后再保存', ApiResponseConstant::STALE_VERSION);
            }
            // 通道组合入口在事务内再次保护接入切换，不能绕过组合表单的在途订单/组类型检查。
            if ($channel['pay_types'] !== [$input->payType] || $channel['receipt_scope_key'] !== $input->scopeKey) {
                if ($this->channels->hasPendingPayments($id)) { throw new BusinessException('通道仍有待付款订单，处理完成后可切换接入', ApiResponseConstant::CONFLICT); }
                if ($channel['pay_types'] !== [$input->payType] && $this->defaults->customGroupsUsingChannel($id) !== []) {
                    throw new BusinessException('请先从原支付方式的轮询组移除此通道', ApiResponseConstant::CONFLICT);
                }
                $this->management->assertNew($configuration['plugin_code']);
                $this->assertPaymentTypeEnabled($input->payType);
            }
        }
        if ($id === null) {
            $this->management->assertNew($configuration['plugin_code']);
            $this->assertPaymentTypeEnabled($input->payType);
        }
        $definition = $this->plugins->definition($configuration['plugin_code']);
        $authorized = $this->scopeAccess->current($configuration, true);
        $scope = $authorized[$input->scopeKey] ?? null;
        if ($scope === null || $this->catalog->typeCode($scope['pay_type_id']) !== $input->payType
            || !in_array($input->payType, $definition->payTypes, true)) {
            throw new BusinessException('通道范围、支付方式或匹配能力未获授权', ApiResponseConstant::VALIDATION_FAILED);
        }
        $result = $id === null ? 'created' : 'updated';
        if ($id === null) {
            // 新通道不存在时先锁真实范围再插入，避免外键共享锁升级竞争。
            $id = $this->channels->create(['plugin_code' => $configuration['plugin_code'],
                'api_config_id' => $configurationId, 'name' => $input->name, 'pay_types' => json_encode([$input->payType], JSON_THROW_ON_ERROR),
                'receipt_scope_key' => $input->scopeKey]);
        }
        $this->channels->saveRules($id, $input->record(), $this->clock->now()->format('Y-m-d H:i:s'));
        $revision = $this->revisions->freezeLocked($configuration, $this->channels->lock($id));
        $this->defaults->syncChannel($id, $input->payType, $input->participatesDefaultRoute);
        $this->audit->record($actor, 'channel.save', 'payment_channel', $id, $result,
            ['plugin_code' => $configuration['plugin_code'], 'plugin_version' => $definition->version,
                'new_version' => $expectedConfigurationVersion, 'enabled' => false]);
        return ['id' => $id, 'revision_id' => $revision, 'enabled' => false];
    }

    /** 组合启停入口；既有事务内仍完整核对账号、快照和材料，停用不会停止历史采集。 */
    public function setEnabledInTransaction(string $id, string $expectedRevision, int $expectedConfigurationVersion,
        bool $enabled, ConfigurationActionContext $actor): void
    {
        $this->requireTransaction();
        $observed = $this->channels->find($id) ?? throw new BusinessException('通道不存在', ApiResponseConstant::NOT_FOUND);
        $this->defaults->lockTypes($observed['pay_types']);

        $configuration = $this->configurations->lock($observed['api_config_id']);
        $channel = $this->channels->lock($id);
        if ($channel['deleted_at'] !== null) { throw new BusinessException('通道已删除', ApiResponseConstant::NOT_FOUND); }
        $this->assertVersion($configuration, $expectedConfigurationVersion);
        if ($channel['api_config_id'] !== $configuration['id']
            || $channel['plugin_code'] !== $configuration['plugin_code'] || $channel['current_revision_id'] !== $expectedRevision) {
            throw new BusinessException('通道已改变，请刷新后再操作', ApiResponseConstant::STALE_VERSION);
        }
        if ($enabled) {
            $this->management->assertNew($configuration['plugin_code']);
            if ((int) $configuration['enabled'] !== 1) { throw new BusinessException('请先启用收款账号采集', ApiResponseConstant::CONFLICT); }
            $definition = $this->plugins->definition($configuration['plugin_code']);
            $snapshot = $this->revisions->snapshot($id, $expectedRevision, true);
            if (!$this->revisions->rulesMatch($channel, $snapshot['channel']) || $snapshot['config_version'] !== $expectedConfigurationVersion) {
                throw new BusinessException('收款账号配置已更新，请重新保存通道后再启用', ApiResponseConstant::CONFLICT);
            }
            $authorized = $this->scopeAccess->current($configuration, true);
            $scope = $authorized[$channel['receipt_scope_key']] ?? null;
            $types = $channel['pay_types'];
            if ($scope === null || !is_array($types) || !array_is_list($types) || count($types) !== 1
                || $this->catalog->typeCode($scope['pay_type_id']) !== $types[0] || !in_array($types[0], $definition->payTypes, true)) {
                throw new BusinessException('通道范围或支付方式不一致', ApiResponseConstant::SERVICE_UNAVAILABLE);
            }
            $this->assertPaymentTypeEnabled($types[0]);
        }
        $this->channels->setEnabled($id, $enabled, $this->clock->now()->format('Y-m-d H:i:s'));
        $this->audit->record($actor, 'channel.enabled', 'payment_channel', $id, $enabled ? 'enabled' : 'disabled',
            ['new_version' => $expectedConfigurationVersion, 'enabled' => $enabled]);
    }

    private function assertVersion(PaymentPluginConf $configuration, int $expected): void
    {
        if ($expected < 1 || (int) $configuration['config_version'] !== $expected) {
            throw new BusinessException('收款配置已改变，请刷新后再操作', ApiResponseConstant::STALE_VERSION);
        }
    }

    /** CLI 与组合表单共用此边界，停用字典后仍能编辑历史规则，但不能新增或启用通道。 */
    private function assertPaymentTypeEnabled(string $code): void
    {
        $type = $this->catalog->lockType($code);
        if ($type === null || (int) $type['enabled'] !== 1) {
            throw new BusinessException('支付方式不存在或已停用', ApiResponseConstant::CONFLICT);
        }
    }
}
