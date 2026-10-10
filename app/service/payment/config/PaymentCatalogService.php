<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\service\system\audit\ConfigurationAuditService;
use app\service\system\audit\ConfigurationActionContext;
use app\service\payment\plugin\PluginDefinition;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant as Code;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentPluginRepository;
use app\service\file\FileAssetService;

/** 支付方式及本地插件目录的管理用例；开关只控制新单，存量执行继续按原配置完成。 */
final class PaymentCatalogService extends BaseService
{
    private const BRANDS = [
        'alipay' => ['alipay-icon.svg','alipay-logo.svg'],
        'wxpay' => ['wechat-pay-icon.svg','wechat-pay-logo.svg'],
        'unionpay' => ['cloudpay-icon.svg','cloudpay-logo.svg'],
    ];

    public function __construct(private readonly PaymentCatalogRepository $types, private readonly PaymentPluginRepository $records,
        private readonly PaymentPluginFactoryService $plugins, private readonly FileAssetService $files,
        private readonly ConfigurationAuditService $audit, private readonly Clock $clock,
        private readonly \app\service\payment\routing\DefaultRoutingService $defaults,
        private readonly \app\repository\payment\config\PaymentPollGroupRepository $groups) {}

    /** @return list<array<string,mixed>> 标识图片只来自内置白名单或已登记的本地图片。 */
    public function types(bool $withReferences = true): array
    {
        $items = [];
        foreach ($this->types->all() as $row) {
            $brand = self::BRANDS[$row['code']] ?? [null,null];
            $items[] = ['id' => $row['id'], 'code' => $row['code'], 'name' => $row['name'],
                'enabled' => (int) $row['enabled'] === 1, 'revision' => (int) $row['revision'], 'sort_order' => (int) $row['sort_order'],
                'icon_asset_id' => $row['icon_asset_id'], 'logo_asset_id' => $row['logo_asset_id'],
                'icon' => $this->files->publicImagePath($row['icon_asset_id']) ?? ($brand[0] === null ? null : '/assets/brand/' . $brand[0]),
                'logo' => $this->files->publicImagePath($row['logo_asset_id']) ?? ($brand[1] === null ? null : '/assets/brand/' . $brand[1]),
                'referenced' => $withReferences ? $this->types->referenced($row['id'], $row['default_poll_group_id']) : false];
        }
        return $items;
    }

    /** 收银台仅获得公开品牌字段，不返回管理版本、图片资产 ID 或引用信息。 */
    public function brands(): array
    {
        return array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['code','name','icon','logo'])), $this->types(false));
    }

    /** 列表包含未同步及代码已移除条目，避免让目录数量掩盖实际安装状态。 */
    public function overview(): array
    {
        $stored = []; foreach ($this->records->all() as $row) { $stored[$row['code']] = $row; }
        $installed = $this->plugins->codes(); $items = [];
        foreach (array_unique([...$installed, ...array_keys($stored)]) as $code) {
            $row = $stored[$code] ?? null; $present = in_array($code, $installed, true);
            $definition = $present ? $this->plugins->definition($code) : null;
            $items[] = ['code' => $code, 'name' => $row['name'] ?? $definition?->name,
                'version' => $row['version'] ?? $definition?->version, 'pay_types' => $row['pay_types'] ?? $definition?->payTypes,
                'class_name' => $row['class_name'] ?? $definition?->className, 'config_schema' => $row['config_schema'] ?? $definition?->configSchema,
                'description' => $row['description'] ?? $definition?->description,
                'updated_at' => $row['updated_at'] ?? null,
                'installed' => $present, 'synchronized' => $row !== null && (!$present || ($row['version'] === $definition->version
                    && \app\common\validation\PluginConfigValidator::sameSchema($definition->configSchema, $row['config_schema'])
                    && $row['description'] === $definition->description && $row['class_name'] === $definition->className && $row['name'] === $definition->name && $row['pay_types'] === $definition->payTypes)),
                'enabled' => $row !== null && (int) $row['enabled'] === 1,
                'revision' => $row === null ? 0 : (int) $row['revision'], 'channel_count' => $this->records->channelCount($code)];
        }
        return ['types' => $this->types(), 'plugins' => $items];
    }

    /** 刷新同步固定目录内插件的完整定义；全程无网络，不隐式启用新插件。 */
    public function refresh(ConfigurationActionContext $actor): void
    {
        $definitions = array_map(fn (string $code) => $this->plugins->definition($code), $this->plugins->codes());
        $this->transaction(function () use ($definitions, $actor): void {
            $this->audit->authorize($actor);
            foreach ($definitions as $definition) {
                $this->records->synchronize(['code' => $definition->code, 'name' => $definition->name, 'version' => $definition->version,
                    'pay_types' => $definition->payTypes, 'class_name' => $definition->className,
                    'config_schema' => $definition->configSchema, 'description' => $definition->description]);
            }
            $this->records->removeUnusedMissing(array_map(static fn (PluginDefinition $definition): string => $definition->code, $definitions));
            $this->audit->record($actor, 'plugin.refresh', 'payment_plugin', null, 'updated', []);
        });
    }

    /** @return list<array<string,mixed>> 后台动态表单只消费已同步、仍安装的数据库定义。 */
    public function definitions(): array
    {
        $items = [];
        foreach ($this->overview()['plugins'] as $row) {
            if (!$row['installed'] || !$row['synchronized']) { continue; }
            $items[] = ['code' => $row['code'], 'name' => $row['name'], 'version' => $row['version'],
                'schema' => $row['config_schema'], 'description' => $row['description'],
                'pay_types' => $row['pay_types'], 'enabled' => $row['enabled']];
        }
        return $items;
    }

    /** 新单选路在自身事务内当前读；读取插件类仍以代码注册表为准。 */
    public function allowsNew(string $code, bool $lock = false): bool
    {
        if (!in_array($code, $this->plugins->codes(), true)) { return false; }
        $plugin = $this->records->find($code, $lock);
        return $plugin !== null && (int) $plugin['enabled'] === 1;
    }

    public function assertNew(string $code): void
    {
        // 当前读确保等待管理锁后使用最新开关；涉及已有配置时，调用方先锁配置再核对插件。
        if (!$this->allowsNew($code, true)) { throw new BusinessException('插件尚未同步、已停用或未安装', Code::CONFLICT); }
    }

    /** @param array{code:string,revision:int,enabled:bool} $input 服务先核对安装与版本，再改变新单开关。 */
    public function pluginEnabled(array $input, ConfigurationActionContext $actor): void
    {
        if ($input['enabled']) { $this->plugins->definition($input['code']); }
        $this->transaction(function () use ($input, $actor): void {
            $this->audit->authorize($actor);
            $plugin = $this->records->find($input['code'], true) ?? throw new BusinessException('请先刷新插件列表', Code::CONFLICT);
            if ((int) $plugin['revision'] !== $input['revision']) { throw new BusinessException('插件状态已变化，请刷新', Code::CONFLICT); }
            $this->records->setEnabled($plugin, $input['enabled'], $this->clock->now()->format('Y-m-d H:i:s'));
            $this->audit->record($actor, 'plugin.enabled', 'payment_plugin', $plugin['id'], $input['enabled'] ? 'enabled' : 'disabled', ['enabled' => $input['enabled']]);
        });
    }

    /** @param array<string,mixed> $input 已通过字段验证；图片有效性和引用限制属于服务规则。 */
    public function saveType(array $input, ConfigurationActionContext $actor): array
    {
        $this->files->publicImagePath($input['icon_asset_id']); $this->files->publicImagePath($input['logo_asset_id']);
        return $this->transaction(function () use ($input, $actor): array {
            $this->audit->authorize($actor);
            $row = $input['id'] === null ? null : $this->types->find($input['id'], true);
            if ($input['id'] !== null && $row === null) { throw new BusinessException('支付方式不存在', Code::NOT_FOUND); }
            if ($row !== null && (int) $row['revision'] !== $input['revision']) { throw new BusinessException('支付方式已修改，请刷新', Code::CONFLICT); }
            $duplicate = $this->types->findCode($input['code']);
            if ($duplicate !== null && ($row === null || $duplicate['id'] !== $row['id'])) { throw new BusinessException('支付方式编码已存在', Code::CONFLICT); }
            if ($row !== null && $row['code'] !== $input['code'] && $this->types->referenced($row['id'], $row['default_poll_group_id'])) {
                throw new BusinessException('支付方式已有引用，不能修改编码', Code::CONFLICT);
            }
            $values = array_intersect_key($input, array_flip(['code','name','sort_order','icon_asset_id','logo_asset_id']));
            $id = $this->types->save($row, $values + ['enabled' => (int) $input['enabled']]);
            if ($row === null) { $this->defaults->addType($this->types->find($id, true)); }
            $this->audit->record($actor, 'payment_type.save', 'payment_type', $id, $row === null ? 'created' : 'updated', ['enabled' => $input['enabled']]);
            return ['id' => $id];
        });
    }

    /** 有任何关系引用就保留字典身份；删除与配置新增共用管理员锁并受外键保护。 */
    public function deleteType(string $id, int $revision, ConfigurationActionContext $actor): void
    {
        $this->transaction(function () use ($id, $revision, $actor): void {
            $this->audit->authorize($actor);
            $row = $this->types->find($id, true) ?? throw new BusinessException('支付方式不存在', Code::NOT_FOUND);
            if ((int) $row['revision'] !== $revision) { throw new BusinessException('支付方式已修改，请刷新', Code::CONFLICT); }
            if ($this->types->referenced($id, $row['default_poll_group_id'])) { throw new BusinessException('支付方式已有引用，请使用停用操作', Code::CONFLICT); }
            if ($row['default_poll_group_id'] !== null) { $this->groups->deleteForType($id, $row['default_poll_group_id']); }
            $this->types->delete($row);
            $this->audit->record($actor, 'payment_type.delete', 'payment_type', $id, 'deleted', []);
        });
    }
}
