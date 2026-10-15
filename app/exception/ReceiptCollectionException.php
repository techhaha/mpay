<?php
declare(strict_types=1);

namespace app\exception;

use InvalidArgumentException;
use RuntimeException;

/** 主动只读采集的固定故障合同，不改变订单状态，不向诊断中拼入平台报文、会话或凭证。 */
final class ReceiptCollectionException extends RuntimeException
{
    public const REASONS = ['configuration_conflict', 'authentication_expired', 'network_error', 'rate_limited', 'scope_mismatch',
        'protocol_error', 'history_gap', 'capacity_exceeded', 'budget_exhausted'];

    /**
     * @param string $reasonCode authentication_expired 只用于平台明确拒绝认证，超时不能归为该类。
     * @param int|null $retryAfterSeconds 经 SDK 校验的限流等待秒数；null 使用系统有界退避。
     */
    public function __construct(public readonly string $reasonCode, public readonly ?int $retryAfterSeconds = null)
    {
        if (!in_array($reasonCode, self::REASONS, true)
            || ($retryAfterSeconds !== null && ($retryAfterSeconds < 1 || $retryAfterSeconds > 86400))) {
            throw new InvalidArgumentException('未知采集故障或恢复间隔');
        }
        parent::__construct('采集未完成：' . $reasonCode);
    }
}
