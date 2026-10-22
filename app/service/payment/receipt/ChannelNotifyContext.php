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
     * @param array<string,mixed> $configuration 通道当前配置或原支付单冻结配置，禁止传给页面。
     * @param DateTimeImmutable $receivedAt 接收时间；无平台付款时间的订单确认以它记录首次确认时间。
     * @param string|null $payNo 支付号入口限定的原单；通道通知/采集由插件定位时为 null。
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
        public ?string $payNo = null,
    ) {
        if ($configurationVersion < 1) {
            throw new InvalidArgumentException('来源上下文的配置版本无效');
        }
    }
}
