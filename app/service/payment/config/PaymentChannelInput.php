<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\util\Money;

/** 已经 HTTP/CLI 共用验证器核验的通道输入；只传递通用参数，不重复声明表单规则。 */
final readonly class PaymentChannelInput
{
    /** 金额均为整数分；范围归属由服务复核。默认参与 null 保留现值，新成员默认参与，不写入通道快照。 */
    public function __construct(
        public string $name,
        public string $scopeKey,
        public string $payType,
        public int $minimumAmount = 1,
        public int $maximumAmount = Money::MAX_CENTS,
        public int $dailyLimitAmount = 0,
        public int $dailyLimitCount = 0,
        public ?string $availableFrom = null,
        public ?string $availableTo = null,
        public ?bool $participatesDefaultRoute = null
    ) {
    }

    /** @return array<string,int|string|null> 日限制 0 表示不限，时段 null 表示全天；金额均为分。 */
    public function record(): array
    {
        return ['name' => $this->name, 'receipt_scope_key' => $this->scopeKey,
            'pay_types' => json_encode([$this->payType], JSON_THROW_ON_ERROR),
            'min_amount' => $this->minimumAmount, 'max_amount' => $this->maximumAmount,
            'daily_limit_amount' => $this->dailyLimitAmount, 'daily_limit_count' => $this->dailyLimitCount,
            'available_from' => $this->availableFrom, 'available_to' => $this->availableTo];
    }
}
