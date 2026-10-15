<?php
declare(strict_types=1);

namespace app\exception;

use app\common\constant\ApiResponseConstant;

/**
 * 推送来源的可预期拒绝；固定原因码供适配器/审计使用，不携带平台正文或签名输入。
 * 继承业务异常使 Callback 将其记为 channel_rejected；程序故障仍抛原异常，不能伪装成无效消息。
 */
final class ReceiptMessageException extends BusinessException
{
    /**
     * @param string $reasonCode 本类白名单内的固定原因，不能由请求指定任意文本。
     * @throws \InvalidArgumentException 开发者使用了未定义的拒绝原因。
     */
    public function __construct(public readonly string $reasonCode)
    {
        [$message, $code] = match ($reasonCode) {
            'invalid_message' => ['来源消息格式不符合合同', ApiResponseConstant::BAD_REQUEST],
            'authentication_failed' => ['来源消息认证失败', ApiResponseConstant::FORBIDDEN],
            'transport_expired' => ['来源消息超出接收时间窗口', ApiResponseConstant::FORBIDDEN],
            'source_mismatch' => ['来源消息不属于允许的来源', ApiResponseConstant::FORBIDDEN],
            'unsupported_message' => ['来源消息类型尚未支持', ApiResponseConstant::VALIDATION_FAILED],
            default => throw new \InvalidArgumentException('来源消息拒绝原因未定义'),
        };
        parent::__construct($message, $code);
    }
}
