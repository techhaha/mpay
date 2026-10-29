<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use app\common\validation\PaymentTypeCode;
use app\common\util\Money;
use app\service\payment\receipt\ReceiptRecordValidator;
use think\Validate;
use UnexpectedValueException;

/** 统一成功结果：原业务金额、实收、真实渠道标识及对应原文；平台认证和匹配由插件完成。 */
final class PaymentPluginNotifyResultValidator extends Validate
{
    protected $rule = [
        'status' => ['require', 'string', 'in' => ['success']],
        'pay_no' => ['require', 'string', 'max' => 64],
        'paid_amount' => ['require', 'integerValue', 'between' => [1, Money::MAX_CENTS]],
        'received_amount' => ['require', 'integerValue', 'between' => [1, Money::MAX_CENTS]],
        'pay_type' => ['require', 'string', 'regex' => PaymentTypeCode::PATTERN],
        'paid_at' => [
            'require',
            'string',
            'regex' => '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
            'dateFormat' => 'Y-m-d H:i:s',
        ],
        'chan_order_no' => ['require', 'string', 'reference'],
        'chan_trade_no' => ['requireNullableReference'],
        'event_key' => ['requireNullableReference'],
    ];

    public function __construct(private readonly ReceiptRecordValidator $records)
    {
        parent::__construct();
    }

    /**
     * 单笔与批量通知使用同一列表合同；提交前拒绝重复订单和重复流水。
     * @param list<array<string,mixed>> $results
     * @return list<array<string,mixed>>
     */
    public function validateList(array $results, ?string $payNo = null): array
    {
        if (!array_is_list($results) || count($results) > 200) {
            throw new UnexpectedValueException('插件通知必须返回最多 200 项的结果列表');
        }
        $orders = [];
        $events = [];
        $trades = [];
        foreach ($results as &$result) {
            if (!is_array($result)) {
                throw new UnexpectedValueException('插件通知结果必须是数组');
            }
            $result = $this->validateResult($result);
            $event = $result['event_key'];
            $trade = $result['chan_trade_no'];
            if (
                ($payNo !== null && $result['pay_no'] !== $payNo) ||
                isset($orders[$result['pay_no']]) ||
                ($event !== null && isset($events[$event])) ||
                ($trade !== null && isset($trades[$trade]))
            ) {
                throw new UnexpectedValueException(
                    '插件通知返回重复订单、重复流水或入口以外的订单',
                );
            }
            $orders[$result['pay_no']] = true;
            if ($event !== null) {
                $events[$event] = true;
            }
            if ($trade !== null) {
                $trades[$trade] = true;
            }
        }
        unset($result);
        return $results;
    }

    /** @return array<string,mixed> 公共十字段校验后附加内部 payload_text；raw 保持原样供采集来源核对，不扩展插件合同。 */
    public function validateResult(array $result): array
    {
        $fields = [
            'status',
            'pay_no',
            'pay_type',
            'paid_amount',
            'received_amount',
            'paid_at',
            'chan_order_no',
            'chan_trade_no',
            'event_key',
            'raw',
        ];
        if (
            array_diff($fields, array_keys($result)) !== [] ||
            array_diff(array_keys($result), $fields) !== []
        ) {
            throw new UnexpectedValueException('插件通知结果字段不符合统一合同');
        }
        // 只用真实交易号补缺订单引用，不能反向补造流水号。
        if (($result['chan_order_no'] ?? '') === '') {
            $result['chan_order_no'] = $result['chan_trade_no'] ?? null;
        }
        if (!(clone $this)->failException(false)->check($result)) {
            throw new UnexpectedValueException('插件通知结果不符合标准字段');
        }
        $result['payload_text'] = $this->records->rawText($result['raw']);
        return $result;
    }

    protected function reference(mixed $value): bool
    {
        return is_string($value) &&
            $value !== '' &&
            strlen($value) <= 128 &&
            mb_check_encoding($value, 'UTF-8');
    }

    protected function requireNullableReference(
        mixed $value,
        mixed $rule,
        array $data,
        string $field,
    ): bool {
        return array_key_exists($field, $data) && ($value === null || $this->reference($value));
    }

    /** 标准插件金额必须为整数分，不能接受可被 integer 规则转换的字符串或浮点。 */
    protected function integerValue(mixed $value): bool
    {
        return is_int($value);
    }
}
