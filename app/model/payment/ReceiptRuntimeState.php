<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 插件查询范围的完成游标和租约运行状态，不承担订单或到账事实。
 * last_heartbeat_at 只表示执行器有活动，不能展示为平台已可用或已具备收款能力。
 *
 * @property string $query_key
 * @property array<string,mixed>|null $cursor_json 完成时间窗口和来源/范围证明，不保存平台单页续页 token。
 * @property int $status ReceiptRuntimeConstant 定义的采集状态，不代表设备实时在线。
 * @property int|null $last_duration_ms 毫秒。
 * @property string|null $claim_token 本轮认领身份，回写时应校验。
 * @property string|null $lease_until
 */
final class ReceiptRuntimeState extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_receipt_runtime_state',
            'schema' => [
                'id' => 'string',
                'query_key' => 'string',
                'cursor_json' => 'json',
                'status' => 'int',
                'last_success_at' => 'string',
                'last_heartbeat_at' => 'string',
                'last_duration_ms' => 'int',
                'next_run_at' => 'string',
                'retry_count' => 'int',
                'reason_code' => 'string',
                'claim_token' => 'string',
                'lease_owner' => 'string',
                'lease_until' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }

}
