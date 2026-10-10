<?php
declare(strict_types=1);

namespace app\service\payment\order;

/**
 * 建单用例的最小结果，仅交给已认证的协议适配器生成收银台地址。
 * accessToken 是随机持有者凭证，禁止记录到日志或在普通订单查询中返回。
 */
final readonly class CreatedOrder
{
    /**
     * @param string|null $payNo 未选择支付方式时为 null，不虚构支付单号。
     * @param bool $reused 此请求是否复用了已经持久化的业务单。
     */
    public function __construct(
        public string $bizNo,
        public ?string $payNo,
        public string $accessToken,
        public bool $reused,
    ) {
    }
}
