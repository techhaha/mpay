<?php
declare(strict_types=1);

namespace app\service\payment\config;

use app\common\util\Money;
use app\common\validation\PaymentTypeCode;

/** 通道通用输入；不携带任何插件字段，单笔限额针对业务订单原金额。 */
final readonly class PaymentChannelInput
{
    /** 金额均为整数分；范围归属由服务复核。默认参与 null 保留现值，新成员默认参与，不写入通道快照。 */
    public function __construct(public string $name, public string $scopeKey, public string $payType,
        public int $minimumAmount = 1, public int $maximumAmount = Money::MAX_CENTS,
        public int $dailyLimitAmount = 0, public int $dailyLimitCount = 0,
        public ?string $availableFrom = null, public ?string $availableTo = null,
        public ?bool $participatesDefaultRoute = null)
    {
        Money::assertRange($maximumAmount);
        Money::assertRange($dailyLimitAmount);
        if (trim($name) === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 128
            || preg_match('/[\x00-\x1f\x7f]/', $name) || $scopeKey === '' || strlen($scopeKey) > 64
            || !mb_check_encoding($scopeKey, 'UTF-8') || !PaymentTypeCode::valid($payType)
            || $minimumAmount < 1 || $minimumAmount > $maximumAmount
            || $dailyLimitCount < 0 || $dailyLimitCount > 1000000
            || ($availableFrom === null) !== ($availableTo === null)
            || ($availableFrom !== null && (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $availableFrom)
                || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $availableTo) || $availableFrom === $availableTo))) {
            throw new \InvalidArgumentException('通道名称、收款范围或金额区间无效');
        }
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
