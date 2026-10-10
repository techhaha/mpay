<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\util\Money;
use think\Validate;

/** SDK/插件返回的标准流水边界；服务在落库和通知调用前检查，不要求插件依赖内部证据类。 */
final class ReceiptRecordValidator extends Validate
{
    protected $rule = ['pay_type' => ['require', 'string', 'regex' => \app\common\validation\PaymentTypeCode::PATTERN], 'event_key' => 'require|max:128',
        'amount' => 'require|integer|gt:0'];

    /**
     * @return array{pay_type:string,event_key:string,amount:int,paid_at:?string,trade_no:?string,remark:?string,
     *     payload_digest:string}
     */
    public function validateRecord(array $record): array
    {
        if (array_diff(array_keys($record), ['pay_type', 'event_key', 'amount', 'paid_at', 'trade_no', 'remark', 'payload_digest']) !== []
            || !$this->check($record) || !is_string($record['event_key'] ?? null) || strlen($record['event_key']) > 128
            || !mb_check_encoding($record['event_key'], 'UTF-8') || !is_int($record['amount'] ?? null)
            || !array_key_exists('paid_at', $record) || !array_key_exists('trade_no', $record) || !array_key_exists('remark', $record)) {
            throw new \UnexpectedValueException('插件流水字段无效');
        }
        Money::assertRange($record['amount']);
        foreach (['paid_at' => 19, 'trade_no' => 128, 'remark' => 256] as $field => $maximum) {
            if ($record[$field] !== null && (!is_string($record[$field]) || $record[$field] === ''
                || ($field === 'trade_no' ? strlen($record[$field]) : mb_strlen($record[$field], 'UTF-8')) > $maximum || !mb_check_encoding($record[$field], 'UTF-8'))) {
                throw new \UnexpectedValueException('插件流水文本字段无效');
            }
        }
        if ($record['paid_at'] !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $record['paid_at'], new \DateTimeZone('Asia/Shanghai'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $record['paid_at']) { throw new \UnexpectedValueException('插件流水时间格式无效'); }
        }
        $value = array_intersect_key($record, array_flip(['pay_type', 'event_key', 'amount', 'paid_at', 'trade_no', 'remark']));
        $value['payload_digest'] = hash('sha256', json_encode([$value['pay_type'], $value['event_key'], $value['amount'],
            $value['paid_at'], $value['trade_no'], $value['remark']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $value;
    }
}
