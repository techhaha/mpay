<?php
declare(strict_types=1);

namespace app\common\validation;

/** 支付方式编码的共同语法；存在性、启用和插件支持由目录/选路服务检查。 */
final class PaymentTypeCode
{
    public const PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';
    public static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
