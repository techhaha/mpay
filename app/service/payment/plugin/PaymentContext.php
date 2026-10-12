<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use app\common\util\Money;
use app\common\validation\PaymentTypeCode;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * 派发服务从已冻结订单/配置构造的内部上下文，不能用客户端金额或当前活动配置替换。
 * configuration 含敏感平台参数，只传给当前已安装插件的独立实例，不得序列化为收银台数据或技术日志。
 */
final readonly class PaymentContext
{
    /**
     * @param int $payAmount 业务金额，分。
     * @param array<string, mixed> $channel 已冻结的通道规则。
     * @param array<string, mixed> $configuration 已读取并校验的历史配置。
     */
    public function __construct(
        public string $bizNo,
        public string $payType,
        public int $payAmount,
        public DateTimeImmutable $expiresAt,
        public string $scopeKey,
        public int $configVersion,
        public array $channel,
        public array $configuration,
    ) {
        Money::assertRange($payAmount);
        if ($payAmount < 1 || !PaymentTypeCode::valid($payType)
            || $bizNo === '' || $scopeKey === ''
            || $configVersion < 1) {
            throw new InvalidArgumentException('支付执行上下文无效');
        }
    }
}
