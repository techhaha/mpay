<?php
declare(strict_types=1);

namespace app\common\constant;

/** 退款自身的状态，不覆盖已收款订单的事实；处理中和未知结果均继续占用可退金额。 */
final class RefundConstant
{
    public const PROCESSING = 0;
    public const SUCCESS = 1;
    public const FAILED = 2;
    public const UNKNOWN = 3;

    public static function label(int $status): string
    {
        return match ($status) {
            self::PROCESSING => '退款处理中',
            self::SUCCESS => '退款成功',
            self::FAILED => '退款未执行或被拒绝',
            self::UNKNOWN => '退款结果待核实',
            default => throw new \UnexpectedValueException('未知退款状态'),
        };
    }
}
