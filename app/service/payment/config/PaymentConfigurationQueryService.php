<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\interface\ChannelNotifyInterface;
use app\common\interface\ReceiptCollectorInterface;
use app\exception\BusinessException;
use app\model\payment\ReceiptScope;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\routing\DefaultRoutingService;
use InvalidArgumentException;
use RuntimeException;

/**
 * 后台配置读模型：在一致数据库视图内组合目录、当前版本及范围，只返回管理表单需要的白名单。
 * 仅已认证的管理员配置入口读取当前 schema 内的完整值，包括密钥；不返回会话或历史配置。
 */
final class PaymentConfigurationQueryService extends BaseService
{
    public function __construct(
        private readonly PaymentPluginConfRepository $configurations, private readonly PaymentChannelRepository $channels,
        private readonly ReceiptScopeRepository $scopes,
        private readonly PaymentCatalogRepository $catalog, private readonly PaymentPluginFactoryService $plugins,
        private readonly PaymentCatalogService $management,
        private readonly DefaultRoutingService $defaults)
    {
    }

    /** @return list<array<string,mixed>> 已同步数据库定义；未同步的新文件通过插件管理刷新后出现。 */
    public function plugins(): array
    {
        return $this->management->definitions();
    }

    /**
     * @param array<string,string|int> $filters 由 ConfigurationQueryValidator 的对应场景提供。
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listing(string $kind, array $filters, int $page, int $limit): array
    {
        return $this->transaction(function () use ($kind, $filters, $page, $limit): array {
            $rows = match ($kind) {
                'channels' => $this->channels->adminPage($filters, $page, $limit),
                default => throw new InvalidArgumentException('未知配置列表'),
            };
            $items = [];
            foreach ($rows['items'] as $row) {
                $item = ['id' => $row['id'], 'name' => $row['name'], 'plugin_code' => $row['plugin_code'],
                    'enabled' => (int) $row['enabled'] === 1, 'updated_at' => $row['updated_at']];
                $items[] = $item + ['configuration_id' => $row['api_config_id'], 'scope_key' => $row['receipt_scope_key'],
                        'pay_types' => $row['pay_types'], 'revision_id' => $row['current_revision_id']];
            }
            return ['items' => $items, 'total' => $rows['total'], 'page' => $page, 'limit' => $limit];
        });
    }

    /**
     * 表单保存必须带本次读出的 config_version，并提交实际配置值；不使用秘密占位值或保留标记。
     *
     * @return array<string,mixed> settings 是插件声明内的完整配置对象；调用方不得将其用于公开接口或日志。
     * 读取不校验配置值；规则变更后仍允许管理员打开表单修正，保存和执行入口负责校验。
     * @throws BusinessException 记录不存在或插件定义未同步。
     */
    public function configuration(string $id): array
    {
        return $this->transaction(function () use ($id): array {
            $configuration = $this->configurations->find($id);
            if ($configuration === null) {
                throw new BusinessException('收款配置不存在', ApiResponseConstant::NOT_FOUND);
            }
            $version = (int) $configuration['config_version'];
            $revision = $this->configurations->revision($id, $version) ?? throw new RuntimeException('配置版本缺失');
            if ($revision['config_json'] !== $configuration['config_json']) { throw new RuntimeException('配置正文与版本不一致'); }
            $plugin = $this->plugin($configuration['plugin_code']);

            $original = $revision['config_json'];
            if (!is_array($original)) { throw new RuntimeException('配置正文无效'); }
            // 已删除字段不再渲染或回传；现有字段保持原值，不用默认值掩盖配置问题。
            $settings = array_intersect_key($original, array_flip(array_column($plugin['schema'], 'field')));
            $records = $this->scopes->configurationScopes($id, false);
            if ($records === []) { throw new RuntimeException('配置范围绑定缺失'); }
            $keys = [];
            $scopes = [];
            $typeCodes = $this->catalog->typeCodes();
            foreach ($records as $scope) {
                $keys[] = $scope['receipt_scope_key'];
                $scopes[] = $this->scope($scope, $typeCodes);
            }
            return ['id' => $id, 'name' => $configuration['name'], 'config_version' => $version,
                'enabled' => (int) $configuration['enabled'] === 1, 'plugin' => $plugin, 'scope_keys' => $keys, 'scopes' => $scopes,
                'settings' => (object) $settings];
        });
    }

    /**
     * @return array<string,mixed> channel 中金额为整数分，时长为秒；快照版本与当前配置版本分别返回。
     * 通道失效仅提示重新保存/启用，查询不修改快照或推测平台健康。
     */
    public function channel(string $id): array
    {
        return $this->transaction(function () use ($id): array {
            $channel = $this->channels->find($id);
            if ($channel === null || $channel['deleted_at'] !== null) {
                throw new BusinessException('通道不存在', ApiResponseConstant::NOT_FOUND);
            }
            $configuration = $this->configurations->find($channel['api_config_id']) ?? throw new RuntimeException('通道配置缺失');
            $types = $channel['pay_types'];
            if (!is_array($types) || count($types) !== 1) { throw new RuntimeException('通道必须有一个明确支付方式'); }
            $revision = $channel['current_revision_id'] === null ? null : $this->channels->revision($id, $channel['current_revision_id']);
            if ($channel['current_revision_id'] !== null && $revision === null) { throw new RuntimeException('通道快照缺失'); }
            $configurationRevision = $this->configurations->revision($configuration['id'], (int) $configuration['config_version'])
                ?? throw new RuntimeException('通道配置版本缺失');
            $definition = $this->plugins->definition($configuration['plugin_code']);
            $scope = $this->scopes->find($channel['receipt_scope_key']) ?? throw new RuntimeException('通道收款范围缺失');
            $plugin = $this->plugins->make($configuration['plugin_code']);
            $push = $plugin instanceof ChannelNotifyInterface;
            $polling = $plugin instanceof ReceiptCollectorInterface;
            return ['id' => $id, 'plugin_code' => $channel['plugin_code'], 'configuration_id' => $channel['api_config_id'],
                'configuration_version' => (int) $configuration['config_version'], 'enabled' => (int) $channel['enabled'] === 1,
                // 只返回当前已配置来源的固定路径；HTTP 入口按当前请求生成域名，不存入通道快照。
                'notification' => ['mode' => $push ? 'push' : ($polling ? 'polling' : 'unavailable'),
                    'method' => $push ? 'POST' : null,
                    'path' => $push ? '/api/pay/' . $id . '/notify' : null],
                'revision_id' => $channel['current_revision_id'], 'snapshot_configuration_version' => $revision === null ? null : (int) $revision['config_version'],
                'participates_default_route' => $this->defaults->participates($id, $types[0]),
                'channel' => ['name' => $channel['name'], 'scope_key' => $channel['receipt_scope_key'], 'pay_type' => $types[0],
                    'minimum_amount' => (int) $channel['min_amount'], 'maximum_amount' => (int) $channel['max_amount'],
                    'daily_limit_amount' => (int) $channel['daily_limit_amount'], 'daily_limit_count' => (int) $channel['daily_limit_count'],
                    'available_from' => $channel['available_from'], 'available_to' => $channel['available_to']]];
        });
    }

    /** @return array<string,mixed> 表单 schema 来自已同步定义，不能混用新代码与旧数据库定义。 */
    private function plugin(string $code): array
    {
        foreach ($this->management->definitions() as $definition) {
            if ($definition['code'] === $code) { return $definition; }
        }
        throw new BusinessException('插件定义未同步或文件缺失，请先到插件管理刷新', ApiResponseConstant::CONFLICT);
    }

    /** @param array<int|string,string> $typeCodes 仅返回内部关联，不包含插件账号参数。 */
    private function scope(ReceiptScope $scope, array $typeCodes): array
    {
        return ['scope_key' => $scope['receipt_scope_key'], 'pay_type' => $typeCodes[$scope['pay_type_id']] ?? throw new RuntimeException('范围支付方式缺失')];
    }
}
