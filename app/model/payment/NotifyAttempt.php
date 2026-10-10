<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 一次持久通知派发意图及脱敏结果，notify_no/attempt_no 唯一。
 * claimed 在发送前落库，租约中断可能发生在网络前或 ACK 后，不能据此断言远端是否收到。
 * HTTP 状态与返回摘要用于排障，是否收到业务 ACK 必须由通知派发合同判定。
 *
 * @property int $attempt_no 同一通知任务内的发送序号。
 * @property string $claim_token 发起该次尝试的租约身份。
 * @property int|null $http_status
 * @property int|null $duration_ms 毫秒。
 * @property string|null $response_body 至多 4096 UTF-8 字节的商户响应片段，仅在已认证后台按文本展示。
 * @property bool $response_truncated 正文被截断或传输中断，不能作为完整响应使用。
 */
final class NotifyAttempt extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_notify_attempt',
            'schema' => [
                'id' => 'string',
                'notify_no' => 'string',
                'attempt_no' => 'int',
                'claim_token' => 'string',
                'started_at' => 'string',
                'finished_at' => 'string',
                'http_status' => 'int',
                'duration_ms' => 'int',
                'result_code' => 'string',
                'response_digest' => 'string',
                'response_body' => 'string',
                'response_truncated' => 'bool',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }

}
