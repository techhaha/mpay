<?php
declare(strict_types=1);

namespace app\common\constant;


/** 采集缓存摘要的状态；心跳、设备在线和订单支付状态均不由此枚举推断。 */
final class ReceiptRuntimeConstant
{
    public const IDLE = 0;
    public const HEALTHY = 1;
    public const RETRYING = 3;
    public const AUTH_REQUIRED = 4;
    public const RATE_LIMITED = 5;
    public const SCOPE_ERROR = 6;
    public const PROTOCOL_ERROR = 7;
    public const PUSH = 9;
    public const PAUSED = 10;

    public const LABELS = [
        self::IDLE => '等待采集', self::HEALTHY => '上次采集或认证活动正常',
        self::RETRYING => '等待重试',
        self::AUTH_REQUIRED => '需要更新平台认证', self::RATE_LIMITED => '平台限流，等待重试',
        self::SCOPE_ERROR => '收款范围不一致', self::PROTOCOL_ERROR => '来源协议或数据异常',
        self::PUSH => '等待认证推送', self::PAUSED => '采集暂停',
    ];

    /** 故障分类只用于运行显示，不能据此改变支付状态。 */
    public static function failure(string $reason): int
    {
        return match ($reason) {
            'configuration_conflict', 'authentication_expired' => self::AUTH_REQUIRED,
            'rate_limited' => self::RATE_LIMITED,
            'scope_mismatch' => self::SCOPE_ERROR,
            'protocol_error', 'history_gap' => self::PROTOCOL_ERROR,
            default => self::RETRYING,
        };
    }
}
