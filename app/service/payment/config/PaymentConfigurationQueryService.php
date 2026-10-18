<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\interface\ChannelNotifyInterface;
use app\common\interface\ReceiptCollectorInterface;
use app\exception\BusinessException;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\routing\DefaultRoutingService;
use RuntimeException;

/**
 * 后台配置读模型：在一致数据库视图内核对版本及关联，只返回管理表单需要的白名单。
 * 仅已认证的管理员配置入口读取当前 schema 内的完整值，包括密钥；不返回会话或历史配置。
 */
final class PaymentConfigurationQueryService extends BaseService
{
    public function __construct(
        private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentChannelRepository $channels,
        private readonly ReceiptScopeRepository $scopes,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly PaymentCatalogService $management,
        private readonly DefaultRoutingService $defaults,
    ) {}

    /** @return list<array<string,mixed>> 已同步数据库定义；未同步的新文件通过插件管理刷新后出现。 */
    public function plugins(): array
    {
        return $this->management->definitions();
    }

    /**
     * 列表只投影配置；运行诊断在运行维护读取，不附带待核对订单和采集缓存查询。
     *
     * @param array<string,string|int> $filters 由 ConfigurationQueryValidator::channels 提供。
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function channels(array $filters, int $page, int $limit): array
    {
        return $this->transaction(function () use ($filters, $page, $limit): array {
            $rows = $this->channels->adminPage($filters, $page, $limit);
            $items = [];
            foreach ($rows['items'] as $row) {
                $item = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'plugin_code' => $row['plugin_code'],
                    'enabled' => (int) $row['enabled'] === 1,
                    'updated_at' => $row['updated_at'],
                ];
                $items[] = $item + [
                    'configuration_id' => $row['api_config_id'],
                    'pay_types' => $row['pay_types'],
                    'revision_id' => $row['current_revision_id'],
                ];
            }
            return [
                'items' => $items,
                'total' => $rows['total'],
                'page' => $page,
                'limit' => $limit,
            ];
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
            $revision =
                $this->configurations->revision($id, $version) ??
                throw new RuntimeException('配置版本缺失');
            if ($revision['config_json'] !== $configuration['config_json']) {
                throw new RuntimeException('配置正文与版本不一致');
            }
            $plugin = $this->management->definition($configuration['plugin_code']);

            $original = $revision['config_json'];
            if (!is_array($original)) {
                throw new RuntimeException('配置正文无效');
            }
            // 已删除字段不再渲染或回传；现有字段保持原值，不用默认值掩盖配置问题。
            $settings = array_intersect_key(
                $original,
                array_flip(array_column($plugin['schema'], 'field')),
            );
            if ($this->scopes->configurationScopes($id, false) === []) {
                throw new RuntimeException('配置范围绑定缺失');
            }
            return [
                'id' => $id,
                'name' => $configuration['name'],
                'config_version' => $version,
                'enabled' => (int) $configuration['enabled'] === 1,
                'plugin' => $plugin,
                'settings' => (object) $settings,
            ];
        });
    }

    /**
     * @return array<string,mixed> channel 中金额为整数分；返回当前配置版本和通道快照 ID 供保存时复核。
     * 通道失效仅提示重新保存/启用，查询不修改快照或推测平台健康。
     */
    public function channel(string $id): array
    {
        return $this->transaction(function () use ($id): array {
            $channel = $this->channels->find($id);
            if ($channel === null || $channel['deleted_at'] !== null) {
                throw new BusinessException('通道不存在', ApiResponseConstant::NOT_FOUND);
            }
            $configuration =
                $this->configurations->find($channel['api_config_id']) ??
                throw new RuntimeException('通道配置缺失');
            $types = $channel['pay_types'];
            if (!is_array($types) || count($types) !== 1) {
                throw new RuntimeException('通道必须有一个明确支付方式');
            }
            // 详情只读取展示和编辑需要的数据；快照/范围一致性由保存及支付执行入口核对。
            $plugin = $this->plugins->make($configuration['plugin_code']);
            $push = $plugin instanceof ChannelNotifyInterface;
            $polling = $plugin instanceof ReceiptCollectorInterface;
            return [
                'id' => $id,
                'plugin_code' => $channel['plugin_code'],
                'configuration_id' => $channel['api_config_id'],
                'configuration_version' => (int) $configuration['config_version'],
                'enabled' => (int) $channel['enabled'] === 1,
                // 只返回通知路径；后台按浏览器当前站点展示完整地址，不存入通道快照。
                'notification' => [
                    'mode' => $push ? 'push' : ($polling ? 'polling' : 'unavailable'),
                    'path' => $push ? '/api/pay/' . $id . '/notify' : null,
                ],
                'revision_id' => $channel['current_revision_id'],
                'participates_default_route' => $this->defaults->participates($id, $types[0]),
                'channel' => [
                    'name' => $channel['name'],
                    'pay_type' => $types[0],
                    'minimum_amount' => (int) $channel['min_amount'],
                    'maximum_amount' => (int) $channel['max_amount'],
                    'daily_limit_amount' => (int) $channel['daily_limit_amount'],
                    'daily_limit_count' => (int) $channel['daily_limit_count'],
                    'available_from' => $channel['available_from'],
                    'available_to' => $channel['available_to'],
                ],
            ];
        });
    }
}
