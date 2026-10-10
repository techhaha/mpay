<?php
declare(strict_types=1);

namespace app\common\constant;

/** BizOrder/PayOrder 共用的交易状态，不与到账流水处理状态或插件 pending/success 混用。 */
final class TradeConstant
{
    public const ORDER_STATUS_CREATED = 0;
    public const ORDER_STATUS_PAYING = 1;
    // 由生命周期核验可信收款事实后推进；二维码生成、接口受理和浏览器回跳都不是该状态依据。
    public const ORDER_STATUS_SUCCESS = 2;
    // 有证据的确定失败；网络超时或结果未知不得推进到该状态。
    public const ORDER_STATUS_FAILED = 3;
    // 本地停止等待，不代表静态二维码已经被平台撤销。
    public const ORDER_STATUS_CLOSED = 4;
    // 本地有效窗口结束，迟到到账事实仍应留存并交由匹配/核对流程判断。
    public const ORDER_STATUS_TIMEOUT = 5;

    // 收款确认依据；未付款为 NULL，不能用 0 冒充已经确认。
    public const CONFIRMATION_RECEIPT = 1;
    public const CONFIRMATION_MANUAL = 2;

    public static function confirmationLabel(?int $source): string
    {
        return match ($source) {
            null => '尚未确认', self::CONFIRMATION_RECEIPT => '插件确认', self::CONFIRMATION_MANUAL => '人工确认',
            default => throw new \UnexpectedValueException('未知收款确认来源'),
        };
    }

    /** 公开页面共用的状态名称；未知值属于数据/程序故障，不能显示成普通待付款。 */
    public static function label(int $status): string
    {
        return match ($status) {
            self::ORDER_STATUS_CREATED => '待发起支付',
            self::ORDER_STATUS_PAYING => '等待付款',
            self::ORDER_STATUS_SUCCESS => '支付成功',
            self::ORDER_STATUS_FAILED => '支付失败',
            self::ORDER_STATUS_CLOSED => '已关闭',
            self::ORDER_STATUS_TIMEOUT => '已超时',
            default => throw new \UnexpectedValueException('未知交易状态'),
        };
    }
}
