<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\util\Money;

/** 管理员核实的真实收款证据，不伪造平台回调，也不把备注或附件号冒充渠道交易号。 */
final readonly class ManualReceiptEvidence
{
    /**
     * @param int $receivedAmount 管理员核实的实际交易金额，分，不是业务金额或期望金额的默认副本。
     * @param \DateTimeImmutable $paidAt 管理员核实的实际付款时间。
     * @param string|null $platformTradeNo 确有真实交易号才填写，否则保留 null。
     * @param string $reference 脱敏核对依据，如账单/附件引用，不能为空或填入真实凭证。
     */
    public function __construct(
        public int $receivedAmount,
        public \DateTimeImmutable $paidAt,
        public ?string $platformTradeNo,
        public string $reference,
    ) {
        Money::assertRange($receivedAmount);
        if (
            $receivedAmount === 0 ||
            trim($reference) === '' ||
            mb_strlen($reference, 'UTF-8') > 1024 ||
            !mb_check_encoding([$reference, $platformTradeNo ?? ''], 'UTF-8') ||
            ($platformTradeNo !== null &&
                ($platformTradeNo === '' || strlen($platformTradeNo) > 128))
        ) {
            throw new \InvalidArgumentException('人工收款证据无效');
        }
    }

    /** @return array{received_amount:int,paid_at:string,platform_trade_no:?string,reference:string} */
    public function toArray(): array
    {
        return [
            'received_amount' => $this->receivedAmount,
            'paid_at' => $this->paidAt
                ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
                ->format('Y-m-d H:i:s'),
            'platform_trade_no' => $this->platformTradeNo,
            'reference' => $this->reference,
        ];
    }
}
