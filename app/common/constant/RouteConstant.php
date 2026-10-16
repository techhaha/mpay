<?php
declare(strict_types=1);

namespace app\common\constant;

use UnexpectedValueException;

/** 轮询组与订单快照共用的选路策略，数据库保存 TINYINT；编码不随显示名称改变。 */
final class RouteConstant
{
    /** 仅由后台通道测试服务使用；不能配置为轮询组策略。 */
    public const DIRECT = 0;
    public const ORDER = 1;
    public const WEIGHTED = 2;
    public const FIRST_AVAILABLE = 3;

    public static function label(int $mode): string
    {
        return match ($mode) {
            self::DIRECT => '指定通道',
            self::ORDER => '顺序轮询',
            self::WEIGHTED => '权重随机',
            self::FIRST_AVAILABLE => '默认优先',
            default => throw new UnexpectedValueException('未知选路策略'),
        };
    }
}
