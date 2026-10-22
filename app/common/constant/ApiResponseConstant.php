<?php
declare(strict_types=1);

namespace app\common\constant;

/** 后台与收银台 JSON 的稳定应用码；易支付协议码和设备 ACK 不使用本集合。 */
final class ApiResponseConstant
{
    public const OK = 200;
    // 仅在业务执行已经持久化且具有恢复责任时使用，不能从网络超时直接推导。
    public const PROCESSING = 20200;
    public const BAD_REQUEST = 40000;
    public const UNAUTHENTICATED = 40100;
    public const FORBIDDEN = 40300;
    public const CSRF_INVALID = 40310;
    public const NOT_FOUND = 40400;
    public const METHOD_NOT_ALLOWED = 40500;
    public const CONFLICT = 40900;
    // 已读取的配置版本过期，前端须重新读取；普通业务冲突仍允许修正后提交。
    public const STALE_VERSION = 40910;
    public const VALIDATION_FAILED = 42200;
    public const OPERATION_UNSUPPORTED = 42210;
    public const PAYMENT_REJECTED = 42220;
    public const TOO_MANY_REQUESTS = 42900;
    public const INTERNAL_ERROR = 50000;
    public const UPSTREAM_ERROR = 50200;
    public const SERVICE_UNAVAILABLE = 50300;

    /** 仅转换本集合的应用码；错误码前 3 位是 HTTP 状态，成功码 200 单独保留。 */
    public static function httpStatus(int $code): int
    {
        return $code === self::OK ? 200 : intdiv($code, 100);
    }
}
