<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 向业务网站通知的持久责任，保存冻结内容和凭证版本，不是通用消息队列。
 * PAY_SUCCESS 按 pay_no 建立事件身份，并以 biz_no/event_type 约束一次业务成功责任。
 *
 * @property string $notify_no
 * @property string $event_type
 * @property string $ref_no PAY_SUCCESS 使用 pay_no。
 * @property string $credential_version_id 重试仍引用原签名密钥版本。
 * @property int $status NotifyConstant 中的调度状态。
 * @property int $retry_count 已持久认领的总次数（含初次），不因进程崩溃或人工动作清零。
 * @property int|null $manual_attempt_no 已审计的单次人工授权；待认领为 retry_count+1，认领后为 retry_count。
 * @property string|null $first_ack_at 首次收到有效商户 ACK 的时间，补发失败不能清空。
 * @property string|null $released_at 从人工暂缓首次转为发送的时间；常规通知为 null。
 * @property string|null $claim_token
 * @property string|null $lease_until
 */
final class NotifyTask extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_notify_task',
            'schema' => [
                'id' => 'string',
                'notify_no' => 'string',
                'event_type' => 'string',
                'ref_no' => 'string',
                'biz_no' => 'string',
                'pay_no' => 'string',
                'notify_url' => 'string',
                'notify_data' => 'json',
                'credential_version_id' => 'string',
                'status' => 'int',
                'retry_count' => 'int',
                'manual_attempt_no' => 'int',
                'next_retry_at' => 'string',
                'last_notify_at' => 'string',
                'first_ack_at' => 'string',
                'released_at' => 'string',
                'last_response' => 'string',
                'claim_token' => 'string',
                'lease_until' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }

    public function payOrder(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(PayOrder::class, 'pay_no', 'pay_no');
    }

    /** 同一业务通知的多次 HTTP 派发历史，不是多笔支付或多次业务成功。 */
    public function attempts(): \think\model\relation\HasMany
    {
        return $this->hasMany(NotifyAttempt::class, 'notify_no', 'notify_no');
    }
}
