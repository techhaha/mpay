<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\receipt\ReceiptScopeService;
use RuntimeException;

/**
 * 将通道规则和平台配置冻结为不可变版本，隔离后续配置修改对旧单的影响。
 * 配置管理生成版本，支付执行按保存的 channel_revision_id 读取；本类不启用通道或验证平台可用性。
 */
final class PaymentChannelRevisionService extends BaseService
{
    public function __construct(
        private readonly PaymentChannelRepository $channels,
        private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly ReceiptScopeService $scopeAccess,
    ) {}

    /**
     * 正式通道写用例复用外层事务：调用方已先锁配置、再锁通道；本方法随后核对目录及范围。
     * 本方法不提交或嵌套开启事务，规则修改、快照指针和配置审计可一起原子提交。
     *
     * @return numeric-string 新通道快照 ID；不代表通道已启用或平台已验证。
     */
    public function freezeLocked(PaymentPluginConf $configuration, PaymentChannel $channel): string
    {
        if (
            (string) $channel['api_config_id'] !== $configuration['id'] ||
            $channel['plugin_code'] !== $configuration['plugin_code']
        ) {
            throw new RuntimeException('通道与收款配置不一致');
        }
        // 连凭证正文一起冻结，仅保存 config_version 指针无法恢复已被覆盖的活动配置。
        $configurationRevision =
            $this->configurations->revision(
                $configuration['id'],
                (int) $configuration['config_version'],
                true,
            ) ?? throw new RuntimeException('收款配置版本缺失');
        $definition = $this->plugins->definition($configuration['plugin_code']);
        if ($configurationRevision['config_json'] !== $configuration['config_json']) {
            throw new RuntimeException('配置版本正文不一致');
        }
        $authorized = $this->scopeAccess->current($configuration, true);
        if (!isset($authorized[$channel['receipt_scope_key']])) {
            throw new RuntimeException('通道范围不在当前配置授权中');
        }
        // 快照只冻结已保存参数；配置值由保存和启用入口校验，不在此重复解释 schema。
        $config = $configuration['config_json'];
        if (!is_array($config)) {
            throw new RuntimeException('插件配置正文无效');
        }
        $channelData = $channel->toArray();
        // 排除活动版本指针与维护时间，避免快照自引用和混入非支付规则字段。
        unset(
            $channelData['current_revision_id'],
            $channelData['created_at'],
            $channelData['updated_at'],
        );
        // 账号身份随配置一起固定；旧单的采集来源不能因活动账号重命名而被重新解释。
        $snapshot = json_encode(
            [
                'channel' => $channelData,
                'config_version' => (int) $configuration['config_version'],
                'configuration' => $config,
                'plugin_version' => $definition->version,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        return $this->channels->appendRevision(
            $channel,
            (int) $configuration['config_version'],
            $snapshot,
        );
    }

    /**
     * 读取指定通道的历史规则和敏感配置；结果仅供内部执行，禁止直接输出到收银台。
     *
     * @param bool $currentRead 建单已锁通道时为 true，使版本指针与正文使用同一当前视图。
     * @return array{channel:array<string,mixed>,config_version:int,configuration:array<string,mixed>,
     *     plugin_version:string} 配置快照不可变，插件版本只用于追踪。
     * @throws RuntimeException 历史版本缺失或配置正文无效；禁止回退到活动配置。
     */
    public function snapshot(
        string $channelId,
        string $revisionId,
        bool $currentRead = false,
    ): array {
        $revision =
            $this->channels->revision($channelId, $revisionId, $currentRead) ??
            throw new RuntimeException('通道版本不存在');
        return $revision['snapshot_json'];
    }

    /**
     * 付款、按支付号回调和退款共用原单快照核验，复用订单关联，不回退到活动配置。
     * 这里只核对冻结结构与归属，不校验 schema 值、不比较当前插件版本，也不增加事务或锁。
     * 各动作的金额、状态、权限及锁内交易复核仍由原入口负责。
     *
     * @return array{channel:array<string,mixed>,config_version:int,configuration:array<string,mixed>,plugin_version:string}
     */
    public function forOrder(PayOrder $pay): array
    {
        $revision = $pay->channelRevision;
        $snapshot = $revision['snapshot_json'] ?? [];
        $channel = $snapshot['channel'] ?? [];
        $receipt = $pay['ext_json']['personal_receipt'] ?? [];
        if (
            $revision === null ||
            $revision['id'] !== $pay['channel_revision_id'] ||
            $revision['channel_id'] !== $pay['channel_id'] ||
            !is_array($snapshot) ||
            !is_array($channel) ||
            !is_array($snapshot['configuration'] ?? null) ||
            ($channel['id'] ?? null) !== $pay['channel_id'] ||
            ($channel['plugin_code'] ?? null) !== $pay['plugin_code'] ||
            ($channel['api_config_id'] ?? null) !== $revision['api_config_id'] ||
            ($channel['receipt_scope_key'] ?? null) !== $pay['receipt_scope_key'] ||
            !is_int($snapshot['config_version'] ?? null) ||
            $snapshot['config_version'] < 1 ||
            $snapshot['config_version'] !== (int) $revision['config_version'] ||
            $snapshot['config_version'] !== ($receipt['config_version'] ?? null) ||
            !is_string($snapshot['plugin_version'] ?? null) ||
            $snapshot['plugin_version'] !== ($receipt['plugin_version'] ?? null)
        ) {
            throw new RuntimeException('原支付单的通道配置快照不一致');
        }
        return $snapshot;
    }

    /**
     * 活动规则与已冻结规则必须一致；不能用新限额选单却让派发按旧快照执行。
     *
     * @param array<string, mixed> $snapshot 已读取的通道快照，缺字段直接判为不匹配。
     */
    public function rulesMatch(PaymentChannel $channel, array $snapshot): bool
    {
        foreach (
            [
                'id',
                'plugin_code',
                'api_config_id',
                'pay_types',
                'receipt_scope_key',
                'min_amount',
                'max_amount',
                'daily_limit_amount',
                'daily_limit_count',
                'available_from',
                'available_to',
            ]
            as $field
        ) {
            if (!array_key_exists($field, $snapshot) || $channel[$field] !== $snapshot[$field]) {
                return false;
            }
        }
        return true;
    }
}
