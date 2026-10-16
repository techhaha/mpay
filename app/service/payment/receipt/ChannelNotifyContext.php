<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use DateTimeImmutable;
use InvalidArgumentException;

/** 服务端生成的单通道回调上下文；采集结果先定位订单，不允许从匿名请求反序列化。 */
final readonly class ChannelNotifyContext
{
    /**
     * @param string $channelId HTTP 路由或采集原单明确关联的通道。
     * @param string $scopeKey 该通道的业务锁范围，不代表平台账号。
     * @param array<string,mixed> $configuration 当前账号配置，执行前由插件工厂校验，禁止传给页面。
     * @param DateTimeImmutable $receivedAt 服务端收到本次消息的时间，适配器不得以它替代原付款时间。
     */
    public function __construct(
        public string $channelId,
        public string $configurationId,
        public int $configurationVersion,
        public string $pluginCode,
        public string $pluginVersion,
        public string $scopeKey,
        public string $payType,
        public array $configuration,
        public DateTimeImmutable $receivedAt,
    ) {
        if ($configurationVersion < 1) {
            throw new InvalidArgumentException('来源上下文的配置版本无效');
        }
    }
}
