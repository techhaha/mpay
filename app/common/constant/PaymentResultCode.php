<?php
declare(strict_types=1);

namespace app\common\constant;

/** 支付执行、到账、商户通知和退款日志的诊断编码。流程状态另用 TINYINT；日志保留可扩展原因码便于排障。 */
final class PaymentResultCode
{
    public const EXECUTION_CLAIMED = 'execution_claimed';
    public const STALE_EXECUTION_RESULT = 'stale_execution_result';
    public const EXECUTION_RESULT_NOT_APPLICABLE = 'execution_result_not_applicable';
    public const EXPIRED_EXECUTION_RESULT = 'expired_execution_result';
    public const EXECUTION_PRESENTED = 'execution_presented';
    public const EXECUTION_UNCERTAIN = 'execution_uncertain';
    public const EXECUTION_REJECTED = 'execution_rejected';
    public const RECEIPT_CONFIRMED = 'receipt_confirmed';
    public const CONFIRMATION_REPLAYED = 'confirmation_replayed';
    public const CHANNEL_ACCEPTED = 'channel_accepted';
    public const CHANNEL_REJECTED = 'channel_rejected';
    public const CHANNEL_FAULT = 'channel_fault';
    public const MANUAL_EVIDENCE_SUPPLEMENTED = 'manual_evidence_supplemented';
    public const LATE_DELIVERY_CONFIRMED = 'late_delivery_confirmed';
    public const CLAIMED = 'claimed';
    public const ACKNOWLEDGED = 'acknowledged';
    public const INVALID_ACK = 'invalid_ack';
    public const TIMEOUT = 'timeout';
    public const CONNECT_ERROR = 'connect_error';
    public const DNS_ERROR = 'dns_error';
    public const TARGET_REJECTED = 'target_rejected';
    public const TLS_ERROR = 'tls_error';
    public const HTTP_STATUS = 'http_status';
    public const RESPONSE_TOO_LARGE = 'response_too_large';
    public const APPLICATION_ERROR = 'application_error';
    public const LEASE_EXPIRED_UNKNOWN = 'lease_expired_unknown';
    public const AUTOMATIC_LIMIT_REACHED = 'automatic_limit_reached';
    public const QUARANTINED_FROZEN_PAYLOAD_INVALID = 'quarantined_frozen_payload_invalid';
    public const QUARANTINED_ATTEMPT_STATE_INVALID = 'quarantined_attempt_state_invalid';
    public const QUARANTINED_SCHEDULE_INVALID = 'quarantined_schedule_invalid';
    public const REFUND_PROCESSING = 'refund_processing';
    public const REFUND_SUCCESS = 'refund_success';
    public const REFUND_PENDING = 'refund_pending';
    public const REFUND_UNKNOWN = 'refund_unknown';
    public const REFUND_FAILED = 'refund_failed';
    public const REFUND_NOT_EXECUTED = 'refund_not_executed';
    public const REFUND_UNSUPPORTED = 'refund_unsupported';
    public const REFUND_REJECTED = 'refund_rejected';
    public const REFUND_FAULT = 'refund_fault';

    /** 未知插件/网络诊断码保留在单独字段，不直接把它解释为成功或失败。 */
    public static function label(?string $code): string
    {
        return match ($code) {
            null => '暂无处理结果',
            self::EXECUTION_CLAIMED => '已认领支付执行',
            self::STALE_EXECUTION_RESULT => '执行身份已变化，忽略迟到结果',
            self::EXECUTION_RESULT_NOT_APPLICABLE => '订单已结束或已处理，忽略执行结果',
            self::EXPIRED_EXECUTION_RESULT => '执行租约已过期，等待恢复',
            self::EXECUTION_PRESENTED => '付款材料已准备',
            self::EXECUTION_UNCERTAIN => '平台受理结果未知',
            self::EXECUTION_REJECTED => '平台明确拒绝',
            self::RECEIPT_CONFIRMED => '到账已核实，订单已确认成功',
            self::CONFIRMATION_REPLAYED => '重复确认已处理，不计为新增收款',
            self::CHANNEL_ACCEPTED => '通知已接收',
            self::CHANNEL_REJECTED => '通知未通过校验或订单匹配，请按请求编号核对',
            self::CHANNEL_FAULT => '通知处理异常，请按请求编号查看技术日志',
            self::MANUAL_EVIDENCE_SUPPLEMENTED => '已补充至人工确认的订单，不计为新增收款',
            self::LATE_DELIVERY_CONFIRMED => '窗口内付款迟到送达，已确认原订单',
            self::CLAIMED => '已安排发送，等待结果',
            self::ACKNOWLEDGED => '业务网站确认接收',
            self::INVALID_ACK => '未收到约定应答，请核对业务网站',
            self::TIMEOUT => '请求超时，远端是否处理未知',
            self::CONNECT_ERROR => '连接失败，请核对原通知目标',
            self::DNS_ERROR => '域名解析失败',
            self::TARGET_REJECTED => '原目标不符合当前出站允许规则',
            self::TLS_ERROR => 'TLS 校验失败，请核对目标证书',
            self::HTTP_STATUS => '业务网站返回非成功 HTTP 状态',
            self::RESPONSE_TOO_LARGE => '业务网站响应超长，已截断记录',
            self::APPLICATION_ERROR => '通知处理异常，请按请求编号核对技术日志',
            self::LEASE_EXPIRED_UNKNOWN => '工作者结果未提交，不能确定远端是否收到',
            self::AUTOMATIC_LIMIT_REACHED => '自动次数或期限已用尽，需要人工核对',
            self::QUARANTINED_FROZEN_PAYLOAD_INVALID => '通知内容异常，已暂停发送',
            self::QUARANTINED_ATTEMPT_STATE_INVALID => '通知发送记录异常，已暂停发送',
            self::QUARANTINED_SCHEDULE_INVALID => '通知调度异常，已暂停发送',
            self::REFUND_PROCESSING => '退款请求已认领',
            self::REFUND_SUCCESS => '退款成功',
            self::REFUND_PENDING => '平台退款处理中',
            self::REFUND_UNKNOWN => '退款结果待核实',
            self::REFUND_FAILED => '平台拒绝退款',
            self::REFUND_NOT_EXECUTED => '退款准备失败，尚未执行',
            self::REFUND_UNSUPPORTED => '此插件不支持接口退款',
            self::REFUND_REJECTED => '平台明确拒绝退款',
            self::REFUND_FAULT => '退款调用异常，结果待核实',
            default => '请结合原因码和技术日志核对',
        };
    }
}
