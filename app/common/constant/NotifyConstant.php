<?php
declare(strict_types=1);

namespace app\common\constant;

/** 商户通知责任的事件与调度状态；通知成功表示业务端确认接收，不改变支付结果。 */
final class NotifyConstant
{
    // 唯一通知身份使用 event_type + ref_no，其中 PAY_SUCCESS 的 ref_no 是 pay_no。
    public const EVENT_PAY_SUCCESS = 'PAY_SUCCESS';
    public const STATUS_PENDING = 0;
    // 已被工作进程认领，不表示远端收到；派发及租约恢复由通知运行模块实现。
    public const STATUS_SENDING = 1;
    public const STATUS_SUCCESS = 2;
    // 自动额度用尽或记录损坏被隔离，具体原因保留在任务中；不能因此把支付成功改回失败。
    public const STATUS_EXHAUSTED = 3;
    // 人工明确暂缓异步通知；仍保留冻结责任，但扫描器不得自动发送。
    public const STATUS_HELD = 4;
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENDING,
        self::STATUS_SUCCESS,
        self::STATUS_EXHAUSTED,
        self::STATUS_HELD,
    ];
}
