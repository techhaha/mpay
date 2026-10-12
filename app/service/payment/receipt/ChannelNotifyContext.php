<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use DateTimeImmutable;
use InvalidArgumentException;

/** 服务端在配置/通道/范围锁下生成的来源上下文，不允许从匿名请求反序列化。 */
final readonly class ChannelNotifyContext
{
    /**
     * @param string|null $channelId HTTP 固定通道；账号级采集为 null，随后按真实范围定位订单通道。
     * @param ReceiptScopeSet $scopes 授权的真实范围集合，不能按支付方式猜测第一个终端。
     * @param array<string,mixed> $configuration 经 schema 校验的当前账号配置，禁止传给页面。
     * @param DateTimeImmutable $receivedAt 服务端收到本次消息的时间，适配器不得以它替代原付款时间。
     */
    public function __construct(public ?string $channelId, public string $configurationId,
        public int $configurationVersion, public string $pluginCode, public string $pluginVersion,
        public ReceiptScopeSet $scopes, public array $configuration, public DateTimeImmutable $receivedAt)
    {
        if ($configurationVersion < 1) {
            throw new InvalidArgumentException('来源上下文缺少授权范围或配置版本');
        }
    }
}
