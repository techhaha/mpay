<?php
declare(strict_types=1);

namespace app\service\payment\collection;

use app\service\payment\receipt\ReceiptScopeSet;

/** 采集的一次冻结授权上下文；配置和范围来自服务端，不接受设备请求直接构造。 */
final readonly class ReceiptCollectionContext
{
    /**
     * @param ReceiptScopeSet $scopes 本轮查询覆盖的流水范围；同一流水账号的每种支付方式对应一个范围。
     * @param array<string,mixed> $configuration 本查询组选定的认证/查询参数；订单识别仍读取各自冻结规则。
     * @param int $fromTimestamp 需要完整查询的起点，Unix 秒；范围为 [fromTimestamp,toTimestamp)。
     * @param int $toTimestamp 完成本轮后才可推进到的截止点，不是某一页最后一条记录时间。
     * @param \DateTimeImmutable $deadline 登录、刷新及查询共用的绝对截止时间，插件不能每页重新获得无限预算。
     */
    public function __construct(
        public string $configurationId,
        public string $accountKey,
        public int $configurationVersion,
        public string $pluginCode,
        public string $pluginVersion,
        public ReceiptScopeSet $scopes,
        #[\SensitiveParameter] public array $configuration,
        public int $fromTimestamp,
        public int $toTimestamp,
        public \DateTimeImmutable $deadline,
        public string $queryKey,
        public ?string $sessionKey,
        public string $authFingerprint,
        /** @var array<int|string,int> 本轮查询覆盖的配置 ID 与版本；不接受客户端提供。 */
        public array $configurationVersions,
    ) {
        if ($configurationVersion < 1 || $fromTimestamp < 1 || $toTimestamp <= $fromTimestamp) {
            throw new \InvalidArgumentException('采集窗口或授权上下文无效');
        }
    }
}
