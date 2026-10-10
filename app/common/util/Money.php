<?php
declare(strict_types=1);

namespace app\common\util;

/**
 * 元字符串与整数分的边界转换，全部计算避免经过浮点数。
 * 单笔金额上限为 999999999 分；统计累计单独格式化，不借此放宽付款金额边界。
 * 零可用于统计，付款业务是否允许零由调用服务判断。
 */
final class Money
{
    public const MAX_CENTS = 999999999;

    /**
     * 解析非负元字符串，允许 1、1.1、1.10；不接受空白、符号、指数或多余精度。
     *
     * @return int 对应的整数分，不做四舍五入。
     * @throws \InvalidArgumentException 格式非法或换算后超过统一金额上限。
     */
    public static function parse(string $yuan): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $yuan, $parts)) {
            throw new \InvalidArgumentException('金额必须是最多两位小数的元字符串');
        }
        // 分别处理整数与小数部分，避免先转浮点再乘 100 引入舍入误差。
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        self::assertRange($cents);
        return $cents;
    }

    /**
     * 将整数分输出为固定两位小数的元字符串，可用于易支付 money 等外部表示。
     *
     * @throws \InvalidArgumentException 分值超出统一金额边界。
     */
    public static function format(int $cents): string
    {
        self::assertRange($cents);
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 统计边界格式化数据库累计的非负整数分；累计值可超过单笔上限，但不能经浮点或科学计数法传入。
     * MySQL DECIMAL 累计最多 65 位，纯字符串补位/切分保持精确；不能用于绕过建单和收款的 assertRange。
     *
     * @param string $cents ORM 原样读取的整数分累计结果。
     */
    public static function formatTotal(string $cents): string
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,64})$/D', $cents)) {
            throw new \InvalidArgumentException('累计金额必须是非负整数分字符串');
        }
        $padded = str_pad($cents, 3, '0', STR_PAD_LEFT);
        return substr($padded, 0, -2) . '.' . substr($padded, -2);
    }

    /**
     * 在整数分上增加非负偏移；通道允许的偏移范围仍由占用服务限制。
     *
     * @param int $offset 增加的分值，不是百分比或元金额。
     * @return int 增加后的整数分。
     * @throws \InvalidArgumentException 基数、偏移或结果越界。
     */
    public static function add(int $cents, int $offset): int
    {
        self::assertRange($cents);
        // 在相加前比较剩余空间，避免用可能溢出的结果做校验。
        if ($offset < 0 || $offset > self::MAX_CENTS - $cents) {
            throw new \InvalidArgumentException('金额偏移越界');
        }
        return $cents + $offset;
    }

    /** @throws \InvalidArgumentException 分值小于零或超过统一上限；不负责通道单笔限额。 */
    public static function assertRange(int $cents): void
    {
        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw new \InvalidArgumentException('金额超出支持范围');
        }
    }
}
