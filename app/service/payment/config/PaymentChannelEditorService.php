<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant as Code;
use app\exception\BusinessException;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPluginConf;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\routing\DefaultRoutingService;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\audit\ConfigurationAuditService;

/**
 * 通道表单的完整用例：配置关联、插件参数、通道快照在一个服务事务中保存。
 * 复用现有校验和审计，不让浏览器按三次独立写入拼出可能不完整的通道。
 */
final class PaymentChannelEditorService extends BaseService
{
    public function __construct(
        private readonly ConfigurationAuditService $audit,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly PaymentPluginConfService $configurationService,
        private readonly PaymentChannelService $channelService,
        private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentChannelRepository $channels,
        private readonly ReceiptScopeRepository $scopes,
        private readonly PaymentCatalogRepository $catalog,
        private readonly DefaultRoutingService $defaults,
        private readonly PaymentChannelRevisionService $revisions,
        private readonly PaymentCatalogService $management,
    ) {}

    /**
     * @param array<string,mixed> $input 已通过 editor-create 场景，settings 含平台秘密。
     * @return array{id:string,revision_id:string,configuration_id:string,configuration_version:int,enabled:bool}
     */
    public function create(array $input, ConfigurationActionContext $actor): array
    {
        $definition = $this->plugins->definition($input['plugin_code']);
        if (!in_array($input['pay_type'], $definition->payTypes, true)) {
            throw new BusinessException('所选插件不支持该支付方式', Code::VALIDATION_FAILED);
        }
        return $this->transaction(function () use ($input, $definition, $actor): array {
            $this->audit->authorize($actor);
            // 配置会登记插件支持的全部方式；必须先按 ID 顺序取齐方式/默认组锁，再锁插件和配置。
            $this->defaults->lockTypes($definition->payTypes);
            $type = $this->catalog->lockType($input['pay_type']);
            if ($type === null || (int) $type['enabled'] !== 1) {
                throw new BusinessException('支付方式已停用或不存在', Code::CONFLICT);
            }
            $this->management->assertNew($definition->code);
            [$configurationId, $version, $scopeKey] = $this->createConfiguration($input, $actor);
            // 新配置已核对插件开关及参数，通道保存复用本事务的检查结果。
            $channel = $this->channelService->writeInTransaction(
                null,
                null,
                $configurationId,
                $version,
                $this->rules(
                    $input['channel'],
                    $scopeKey,
                    $input['pay_type'],
                    $input['participates_default_route'] ?? null,
                ),
                $actor,
            );
            if ($input['enabled'] ?? false) {
                $this->channelService->setEnabledInTransaction(
                    $this->configurations->lock($configurationId),
                    $this->channels->lock($channel['id']),
                    true,
                    $actor,
                );
                $channel['enabled'] = true;
            }
            return $channel + [
                'configuration_id' => $configurationId,
                'configuration_version' => $version,
            ];
        });
    }

    /**
     * 编辑通道通用属性及支付接入；切换插件时新参数与通道在同一事务内建立。
     * 切换插件/支付方式前保护待付款订单和组类型；旧单快照不改写。
     */
    public function save(array $input, ConfigurationActionContext $actor): array
    {
        $observed =
            $this->channels->find($input['id']) ??
            throw new BusinessException('通道不存在', Code::NOT_FOUND);
        $definition = $this->plugins->definition($input['plugin_code']);
        if (!in_array($input['pay_type'], $definition->payTypes, true)) {
            throw new BusinessException('所选插件不支持该支付方式', Code::VALIDATION_FAILED);
        }
        return $this->transaction(function () use ($input, $observed, $definition, $actor): array {
            $this->audit->authorize($actor);
            // 更换插件时也会登记全部支持方式，不能持配置/插件锁后再补取其他方式锁。
            $this->defaults->lockTypes(
                array_unique([...$observed['pay_types'], ...$definition->payTypes]),
            );
            $configuration = $this->configurations->lock($observed['api_config_id']);
            $channel = $this->channels->lock($input['id']);
            $this->assertCurrent($configuration, $channel, $input);
            $typeChanged = $channel['pay_types'] !== [$input['pay_type']];
            $pluginChanged = $channel['plugin_code'] !== $definition->code;
            $bindingChanged = $typeChanged || $pluginChanged;
            if ($bindingChanged) {
                if ($this->channels->hasPendingPayments($channel['id'])) {
                    throw new BusinessException(
                        '通道仍有待付款订单，处理完成后可切换支付方式或插件',
                        Code::CONFLICT,
                    );
                }
                if (
                    $typeChanged &&
                    $this->defaults->customGroupsUsingChannel($channel['id']) !== []
                ) {
                    throw new BusinessException(
                        '请先从原支付方式的轮询组移除此通道，再修改支付方式',
                        Code::CONFLICT,
                    );
                }
                $this->management->assertNew($definition->code);
                $type = $this->catalog->lockType($input['pay_type']);
                if ($type === null || (int) $type['enabled'] !== 1) {
                    throw new BusinessException('支付方式已停用或不存在', Code::CONFLICT);
                }
            }
            if ($pluginChanged) {
                [$configurationId, $version, $scopeKey] = $this->createConfiguration(
                    $input,
                    $actor,
                );
            } else {
                if ($input['settings'] !== []) {
                    throw new BusinessException(
                        '请使用“配置”修改当前插件参数',
                        Code::VALIDATION_FAILED,
                    );
                }
                $configurationId = $configuration['id'];
                $version = (int) $configuration['config_version'];
                $scopeKey = $this->scopeForType($configurationId, $input['pay_type']);
            }
            if ($bindingChanged) {
                $this->channels->changeConfiguration(
                    $channel['id'],
                    $configurationId,
                    $definition->code,
                );
            }
            $saved = $this->channelService->writeInTransaction(
                $channel['id'],
                $input['expected_revision'],
                $configurationId,
                $version,
                $this->rules(
                    $input['channel'],
                    $scopeKey,
                    $input['pay_type'],
                    $input['participates_default_route'] ?? null,
                ),
                $actor,
            );
            return $saved + [
                'configuration_id' => $configurationId,
                'configuration_version' => $version,
            ];
        });
    }

    /** 通道创建自己的参数记录；客户端不能提交已有配置 ID。 */
    private function createConfiguration(array $input, ConfigurationActionContext $actor): array
    {
        $created = $this->configurationService->createInTransaction(
            $input['plugin_code'],
            $input['channel']['name'],
            $input['settings'],
            $input['enabled'] ?? false,
            $actor,
        );
        return [
            $created['id'],
            $created['config_version'],
            $this->scopeForType($created['id'], $input['pay_type']),
        ];
    }

    /** 从当前配置已登记的支付方式关联取得业务锁范围。 */
    private function scopeForType(string $id, string $type): string
    {
        $keys = [];
        foreach ($this->scopes->configurationScopes($id, false) as $scope) {
            $key = $scope['receipt_scope_key'];
            if ($this->catalog->typeCode($scope['pay_type_id']) === $type) {
                $keys[] = $key;
            }
        }
        if (count($keys) !== 1) {
            throw new BusinessException('插件配置缺少唯一的支付方式关联', Code::CONFLICT);
        }
        return $keys[0];
    }

    /**
     * 插件参数独立保存；当前通道生成新快照并保持启停状态，已有订单继续引用原快照。
     * 不通过额外调用“编辑通道”来完成配置更新，版本检查及快照刷新在同一事务中结束。
     * @param array<string,mixed> $input plugin-save 场景，配置正文不得记录到日志。
     */
    public function configure(array $input, ConfigurationActionContext $actor): array
    {
        $observed =
            $this->channels->find($input['id']) ??
            throw new BusinessException('通道不存在', Code::NOT_FOUND);
        $definition = $this->plugins->definition($observed['plugin_code']);
        return $this->transaction(function () use ($input, $observed, $definition, $actor): array {
            $this->audit->authorize($actor);
            $this->defaults->lockTypes(
                array_unique([...$observed['pay_types'], ...$definition->payTypes]),
            );
            $configuration = $this->configurations->lock($observed['api_config_id']);
            $this->channels->lockForConfiguration($configuration['id']);
            $channel = $this->channels->lock($input['id']);
            $this->assertCurrent($configuration, $channel, $input);
            $saved = $this->configurationService->saveInTransaction(
                $configuration,
                $configuration['name'],
                $input['settings'],
                $actor,
            );
            if ($saved['config_version'] !== $input['expected_configuration_version']) {
                $updated = $this->configurations->lock($configuration['id']);
                $this->revisions->freezeLocked($updated, $this->channels->lock($channel['id']));
            }
            return $saved;
        });
    }

    /**
     * 启用时同时完成账号采集授权；停用只停止该通道新单，历史到账采集继续保留。
     *
     * @param array{id:string,expected_revision:string,expected_configuration_version:int,enabled:bool} $input 经验证的开关及原版本。
     * @throws BusinessException 配置版本冲突、已删除、插件参数不完整或接入关联不一致。
     */
    public function setEnabled(array $input, ConfigurationActionContext $actor): void
    {
        $observed =
            $this->channels->find($input['id']) ??
            throw new BusinessException('通道不存在', Code::NOT_FOUND);
        $this->transaction(function () use ($input, $observed, $actor): void {
            $this->audit->authorize($actor);
            $this->defaults->lockTypes($observed['pay_types']);
            $configuration = $this->configurations->lock($observed['api_config_id']);
            $channel = $this->channels->lock($input['id']);
            $this->assertCurrent($configuration, $channel, $input);
            if ($input['enabled']) {
                $this->management->assertNew($configuration['plugin_code']);
                $this->configurationService->enableInTransaction($configuration, $actor);
            }
            $this->channelService->setEnabledInTransaction(
                $configuration,
                $channel,
                $input['enabled'],
                $actor,
            );
        });
    }

    /**
     * 删除只标记当前配置不可选，保留实体及快照；管理员锁串行化组成员引用变更。
     *
     * @param array{id:string,expected_revision:string,expected_configuration_version:int} $input 经验证的通道和原版本。
     * @throws BusinessException 版本冲突、已删除、仍有待付款订单或轮询组引用；拒绝时不写删除标记。
     */
    public function delete(array $input, ConfigurationActionContext $actor): void
    {
        $observed =
            $this->channels->find($input['id']) ??
            throw new BusinessException('通道不存在', Code::NOT_FOUND);
        $this->transaction(function () use ($input, $observed, $actor): void {
            $this->audit->authorize($actor);
            $this->defaults->lockTypes($observed['pay_types']);
            $configuration = $this->configurations->lock($observed['api_config_id']);
            $channel = $this->channels->lock($input['id']);
            $this->assertCurrent($configuration, $channel, $input);
            if ($this->channels->hasPendingPayments($channel['id'])) {
                throw new BusinessException('通道仍有待付款订单，请先处理订单', Code::CONFLICT);
            }
            $references = $this->defaults->customGroupsUsingChannel($channel['id']);
            if ($references !== []) {
                throw new BusinessException(
                    '请先在轮询组中移除该通道，再执行删除',
                    Code::CONFLICT,
                    ['groups' => array_column($references, 'name')],
                );
            }
            $this->defaults->removeChannel($channel['id']);
            $this->channels->markDeleted($channel['id'], date('Y-m-d H:i:s'));
            $this->audit->record(
                $actor,
                'channel.delete',
                'payment_channel',
                $channel['id'],
                'deleted',
                ['enabled' => false],
            );
        });
    }

    private function assertCurrent(
        PaymentPluginConf $configuration,
        PaymentChannel $channel,
        array $input,
    ): void {
        if ($channel['deleted_at'] !== null) {
            throw new BusinessException('通道已删除', Code::NOT_FOUND);
        }
        if (
            $channel['api_config_id'] !== $configuration['id'] ||
            $channel['plugin_code'] !== $configuration['plugin_code'] ||
            $channel['current_revision_id'] !== $input['expected_revision'] ||
            (int) $configuration['config_version'] !== $input['expected_configuration_version']
        ) {
            throw new BusinessException('通道配置已改变，请刷新后再操作', Code::STALE_VERSION);
        }
    }

    /** @param array<string,int|string|null> $values 已校验通道表单；金额为分。 */
    private function rules(
        array $values,
        string $scope,
        string $type,
        ?bool $participates,
    ): PaymentChannelInput {
        return new PaymentChannelInput(
            $values['name'],
            $scope,
            $type,
            $values['minimum_amount'],
            $values['maximum_amount'],
            $values['daily_limit_amount'],
            $values['daily_limit_count'],
            $values['available_from'],
            $values['available_to'],
            $participates,
        );
    }
}
