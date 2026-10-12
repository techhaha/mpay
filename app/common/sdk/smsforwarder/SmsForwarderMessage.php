<?php
declare(strict_types=1);

namespace app\common\sdk\smsforwarder;

/**
 * SmsForwarder 兼容表单经过结构/传输校验后的内容，仍不是可信到账事件。
 * from/content 未被兼容签名覆盖；后续平台解析必须独立检查模板、金额和原始证据。
 */
final readonly class SmsForwarderMessage
{
    // 固定能力事实，防止调用者将“时间戳 HMAC 正确”升级解释为正文完整性证明。
    public const BODY_AUTHENTICATED = false;

    /**
     * @param string $from 发送工具声称的 App 包名/来源；只做精确允许列表匹配，不证明真实设备身份。
     * @param string $content 表单解码后的原始通知文本，不 trim、猜字段或写入日志。
     * @param int $transportTimestampMilliseconds 发送请求的 Unix 毫秒时间，不是付款时间或事件 ID。
     */
    public function __construct(public string $from, public string $content,
        public int $transportTimestampMilliseconds)
    {
    }
}
