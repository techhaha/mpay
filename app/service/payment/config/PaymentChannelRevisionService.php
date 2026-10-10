<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\service\payment\plugin\PaymentPluginFactoryService;
use app\common\base\BaseService;
use app\common\validation\PluginConfigValidator;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\service\payment\receipt\ReceiptScopeService;

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
        private readonly PluginConfigValidator $validator,
        private readonly ReceiptScopeService $scopeAccess,
    ) {
    }

    /**
     * 在独立短事务内锁定配置和通道后追加快照，不允许嵌套在支付创建事务中。
     * 冻结前再次核验已安装原版本的 schema，禁止用空 schema 或当前默认值掩盖配置缺失。
     *
     * @return numeric-string 新快照的数据库 ID，供支付单固定引用。
     * @throws \RuntimeException 通道/配置缺失、归属不符或配置正文无效。
     * @throws \JsonException 快照不能编码为 JSON。
     */
    public function freeze(string $channelId): string
    {
        // 先找出锁目标，不反向先锁通道；事务中还需检查引用是否已被并发修改。
        $configurationId = $this->channels->configurationId($channelId);
        return $this->transaction(function () use ($channelId, $configurationId): string {
            // 配置操作先锁配置、后锁通道；不与交易生命周期的锁混用。
            $configuration = $this->configurations->lock($configurationId);
            $channel = $this->channels->lock($channelId);
            return $this->freezeLocked($configuration, $channel);
        });
    }

    /**
     * 正式通道写用例复用外层事务：调用方已先锁配置、再锁通道；本方法随后核对目录及范围。
     * 本方法不提交或嵌套开启事务，规则修改、快照指针和配置审计可一起原子提交。
     *
     * @return numeric-string 新通道快照 ID；不代表通道已启用或平台已验证。
     */
    public function freezeLocked(PaymentPluginConf $configuration, PaymentChannel $channel): string
    {
        if ((string) $channel['api_config_id'] !== $configuration['id']
            || $channel['plugin_code'] !== $configuration['plugin_code']) {
            throw new \RuntimeException('通道与收款配置不一致');
        }
        // 连凭证正文一起冻结，仅保存 config_version 指针无法恢复已被覆盖的活动配置。
        $configurationRevision = $this->configurations->revision($configuration['id'], (int) $configuration['config_version'], true)
            ?? throw new \RuntimeException('收款配置版本缺失');
        $definition = $this->plugins->definition($configuration['plugin_code']);
        if ($configurationRevision['config_json'] !== $configuration['config_json']) {
            throw new \RuntimeException('配置版本正文不一致');
        }
        $authorized = $this->scopeAccess->current($configuration, true);
        if (!isset($authorized[$channel['receipt_scope_key']])) {
            throw new \RuntimeException('通道范围不在当前配置授权中');
        }
        $config = $this->validator->validate($definition->configSchema, $configuration['config_json']);
        $channelData = $channel->toArray();
        // 排除活动版本指针与维护时间，避免快照自引用和混入非支付规则字段。
        unset($channelData['current_revision_id'], $channelData['created_at'], $channelData['updated_at']);
        // 账号身份随配置一起固定；旧单的采集来源不能因活动账号重命名而被重新解释。
        $snapshot = json_encode([
            'channel' => $channelData, 'account_key' => $configuration['account_key'],
            'config_version' => (int) $configuration['config_version'], 'configuration' => $config,
            'plugin_version' => $definition->version,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return $this->channels->appendRevision($channel, (int) $configuration['config_version'], $snapshot, hash('sha256', $snapshot));
    }

    /**
     * 读取指定通道的历史规则和敏感配置；结果仅供内部执行，禁止直接输出到收银台。
     *
     * @param bool $currentRead 建单已锁通道时为 true，使版本指针与正文使用同一当前视图。
     * @return array{channel:array<string,mixed>,account_key?:string,config_version:int,configuration:array<string,mixed>,
     *     plugin_version?:string} 配置快照不可变，插件版本只用于追踪。
     * @throws \RuntimeException 历史版本缺失或配置正文无效；禁止回退到活动配置。
     */
    public function snapshot(string $channelId, string $revisionId, bool $currentRead = false): array
    {
        $revision = $this->channels->revision($channelId, $revisionId, $currentRead) ?? throw new \RuntimeException('通道版本不存在');
        return $revision['snapshot_json'];
    }

    /**
     * 活动规则与已冻结规则必须一致；不能用新限额选单却让派发按旧快照执行。
     *
     * @param array<string, mixed> $snapshot 已读取的通道快照，缺字段直接判为不匹配。
     */
    public function rulesMatch(PaymentChannel $channel, array $snapshot): bool
    {
        foreach (['id', 'plugin_code', 'api_config_id', 'pay_types', 'receipt_scope_key',
            'min_amount', 'max_amount', 'daily_limit_amount', 'daily_limit_count', 'available_from', 'available_to'] as $field) {
            if (!array_key_exists($field, $snapshot) || $channel[$field] !== $snapshot[$field]) {
                return false;
            }
        }
        return true;
    }

}
