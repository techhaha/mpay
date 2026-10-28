<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\util\Money;
use app\common\validation\PaymentTypeCode;
use JsonException;
use think\Validate;
use UnexpectedValueException;

/** SDK/插件返回的标准流水边界；服务在落库和通知调用前检查，不要求插件依赖内部证据类。 */
final class ReceiptRecordValidator extends Validate
{
    protected $rule = [
        'pay_type' => ['require', 'string', 'regex' => PaymentTypeCode::PATTERN],
        'event_key' => ['require', 'string', 'utf8Text', 'byteLength' => 128],
        'amount' => ['require', 'integerValue', 'between' => [1, Money::MAX_CENTS]],
        // MySQL DATETIME 年份下限为 1000；平台已转为上海时区，内部不再来回转换。
        'paid_at' => [
            'requireNullableText' => 19,
            'regex' => '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
            'dateFormat' => 'Y-m-d H:i:s',
        ],
        'trade_no' => ['requireNullableText' => 128],
        'remark' => ['requireNullableText' => 256],
    ];

    /**
     * @return array{pay_type:string,event_key:string,amount:int,paid_at:?string,trade_no:?string,remark:?string,
     *     payload_digest:string,raw:array|string,extra?:array}
     */
    public function validateRecord(array $record): array
    {
        if (
            array_diff(array_keys($record), [
                'pay_type',
                'event_key',
                'amount',
                'paid_at',
                'trade_no',
                'remark',
                'payload_digest',
                'extra',
                'raw',
            ]) !== [] ||
            !array_key_exists('raw', $record) ||
            (!is_array($record['raw']) && !is_string($record['raw'])) ||
            !(clone $this)->failException(false)->check($record)
        ) {
            throw new UnexpectedValueException('插件流水字段无效');
        }
        $value = array_intersect_key(
            $record,
            array_flip(['pay_type', 'event_key', 'amount', 'paid_at', 'trade_no', 'remark']),
        );
        $value['payload_digest'] = hash(
            'sha256',
            json_encode(
                [
                    $value['pay_type'],
                    $value['event_key'],
                    $value['amount'],
                    $value['paid_at'],
                    $value['trade_no'],
                    $value['remark'],
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        );
        // 平台范围补充信息只供插件匹配，不能覆盖标准字段；完整报文和凭证不得放入此处。
        if (array_key_exists('extra', $record)) {
            if (!is_array($record['extra'])) {
                throw new UnexpectedValueException('流水扩展必须为数组');
            }
            $extra = $record['extra'];
            ksort($extra);
            $encoded = json_encode($extra, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE, 16);
            if (strlen($encoded) > 4096) {
                throw new UnexpectedValueException('流水扩展超出大小限制');
            }
            $value['extra'] = $extra;
            $value['payload_digest'] = hash('sha256', $value['payload_digest'] . ':' . $encoded);
        }
        // 原文仅随当前批次留在内存，不能进入内容匹配摘要；最终匹配后才保存。
        $value['raw'] = $record['raw'];
        return $value;
    }

    /** 原文只接受文本或有界纯 JSON；此处不截断，仓储在最终确认事务内限制保存长度。 */
    public function rawText(mixed $raw): string
    {
        if (!is_string($raw) && !is_array($raw)) {
            throw new UnexpectedValueException('通知原文必须是字符串或数组');
        }
        self::assertRawValue($raw);
        try {
            $text = is_string($raw)
                ? $raw
                : json_encode(
                    $raw,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    32,
                );
        } catch (JsonException $error) {
            throw new UnexpectedValueException('通知原文不是有效 JSON', 0, $error);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new UnexpectedValueException('通知原文不是有效 UTF-8');
        }
        return $text;
    }

    private static function assertRawValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 32 || is_object($value) || is_resource($value)) {
            throw new UnexpectedValueException('通知原文必须是有界纯 JSON');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertRawValue($item, $depth + 1);
            }
        }
    }

    protected function integerValue(mixed $value): bool
    {
        return is_int($value);
    }
    protected function utf8Text(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }
    protected function byteLength(string $value, int $maximum): bool
    {
        return strlen($value) <= $maximum;
    }

    /** 可空文本必须显式提供；交易号按字节限制，备注按字符限制。 */
    protected function requireNullableText(
        mixed $value,
        int $maximum,
        array $data,
        string $field,
    ): bool {
        return array_key_exists($field, $data) &&
            ($value === null ||
                (is_string($value) &&
                    $value !== '' &&
                    mb_check_encoding($value, 'UTF-8') &&
                    ($field === 'trade_no' ? strlen($value) : mb_strlen($value, 'UTF-8')) <=
                        $maximum));
    }
}
