<?php
declare(strict_types=1);

namespace app\exception;

use InvalidArgumentException;
use RuntimeException;

/** 采集故障分类用于重试；诊断只供管理员排查，不改变订单状态或携带完整平台报文。 */
final class ReceiptCollectionException extends RuntimeException
{
    public const REASONS = [
        'configuration_conflict',
        'authentication_expired',
        'network_error',
        'rate_limited',
        'scope_mismatch',
        'protocol_error',
        'history_gap',
        'capacity_exceeded',
        'budget_exhausted',
    ];

    /** @var array<string,string|int|bool|null> 插件/SDK 提供的安全诊断，消息最多 2048 字节。 */
    public readonly array $diagnostic;

    /**
     * @param string $reasonCode authentication_expired 只用于平台明确拒绝认证，超时不能归为该类。
     * @param int|null $retryAfterSeconds 经 SDK 校验的限流等待秒数；null 使用系统有界退避。
     * @param array{source?:string,stage?:string,platform_code?:string,message?:string,http_status?:int} $diagnostic
     * 平台文案由 SDK 提取且移除凭证；这里只限制长度，不解释平台错误码，也不保存原始响应。
     */
    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $retryAfterSeconds = null,
        array $diagnostic = [],
    ) {
        if (
            !in_array($reasonCode, self::REASONS, true) ||
            ($retryAfterSeconds !== null && ($retryAfterSeconds < 1 || $retryAfterSeconds > 86400))
        ) {
            throw new InvalidArgumentException('未知采集故障或恢复间隔');
        }
        $message = mb_scrub($diagnostic['message'] ?? '', 'UTF-8');
        $message = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $message);
        $this->diagnostic = [
            'source' => $diagnostic['source'] ?? 'plugin',
            'stage' => mb_strcut($diagnostic['stage'] ?? '采集处理', 0, 64, 'UTF-8'),
            'platform_code' => isset($diagnostic['platform_code'])
                ? mb_strcut($diagnostic['platform_code'], 0, 128, 'UTF-8')
                : null,
            'message' => mb_strcut($message, 0, 2048, 'UTF-8'),
            'http_status' => $diagnostic['http_status'] ?? null,
            'message_truncated' => strlen($message) > 2048,
        ];
        parent::__construct('采集未完成：' . $reasonCode);
    }
}
