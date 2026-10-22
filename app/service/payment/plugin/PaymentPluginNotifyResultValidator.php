<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use app\common\validation\PaymentTypeCode;
use app\service\payment\receipt\ReceiptRecordValidator;
use think\Validate;
use UnexpectedValueException;

/** 成功结果的公共字段及可选流水结构；平台来源认证和字段解释由具体插件完成。 */
final class PaymentPluginNotifyResultValidator extends Validate
{
    protected $rule = [
        'status' => ['require', 'string', 'in' => ['success']],
        'pay_no' => ['require', 'string', 'max' => 64],
        'paid_amount' => ['require', 'integerValue', 'gt' => 0],
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
            $event = $result['receipt']['event_key'] ?? null;
            $trade = $result['receipt']['trade_no'] ?? null;
            if (
                ($payNo !== null && $result['pay_no'] !== $payNo) ||
                isset($orders[$result['pay_no']]) ||
                ($event !== null && isset($events[$event])) ||
                ($trade !== null && isset($trades[$trade]))
            ) {
                throw new UnexpectedValueException('插件通知返回重复订单、重复流水或入口以外的订单');
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

    /** @return array<string,mixed> 成功事实；存在 receipt 时完整核验流水，否则核验明确的平台订单确认字段。 */
    public function validateResult(array $result): array
    {
        // 与 pay 结果使用相同的单向回退；后续仍核对编号格式、原单归属及流水证据。
        if (($result['chan_order_no'] ?? '') === '') {
            $result['chan_order_no'] = $result['chan_trade_no'] ?? null;
        }
        if (!(clone $this)->failException(false)->check($result)) {
            throw new UnexpectedValueException('插件通知结果不符合标准字段');
        }
        if (array_key_exists('receipt', $result)) {
            if (!is_array($result['receipt'])) {
                throw new UnexpectedValueException('插件流水结果必须是有效数组');
            }
            $result['receipt'] = $this->records->validateRecord($result['receipt']);
        } else {
            $rules = [
                'pay_type' => ['require', 'string', 'regex' => PaymentTypeCode::PATTERN],
                'chan_order_no' => ['require', 'string', static fn(mixed $value): bool =>
                    is_string($value) && strlen($value) <= 128 && mb_check_encoding($value, 'UTF-8')],
                'paid_at' => ['dateFormat' => 'Y-m-d H:i:s'],
            ];
            if (
                !(new Validate())->rule($rules)->check($result) ||
                !array_key_exists('paid_at', $result) ||
                ($result['paid_at'] !== null && (!is_string($result['paid_at']) || $result['paid_at'] === '')) ||
                !array_key_exists('chan_trade_no', $result) ||
                ($result['chan_trade_no'] !== null && (
                    !is_string($result['chan_trade_no']) ||
                    $result['chan_trade_no'] === '' ||
                    strlen($result['chan_trade_no']) > 128 ||
                    !mb_check_encoding($result['chan_trade_no'], 'UTF-8')
                ))
            ) {
                throw new UnexpectedValueException('插件订单确认结果不符合标准字段');
            }
        }
        return $result;
    }

    /** 标准插件金额必须为整数分，不能接受可被 integer 规则转换的字符串或浮点。 */
    protected function integerValue(mixed $value): bool
    {
        return is_int($value);
    }
}
