<?php
declare(strict_types=1);

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 人工确认、关闭和重新通知等支付操作的专用审计记录。
 * 由动作服务记录操作者、原因和前后状态；模型不会因保存审计而自动执行订单动作。
 *
 * @property string $pay_no
 * @property string $admin_user_id
 * @property string $reason
 * @property int $before_status TradeConstant 交易状态。
 * @property int $after_status TradeConstant 交易状态。
 */
final class PayOrderOperationLog extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_pay_order_operation_log',
            'schema' => [
                'id' => 'string',
                'pay_no' => 'string',
                'admin_user_id' => 'string',
                'request_id' => 'string',
                'action' => 'string',
                'reason' => 'string',
                'evidence_json' => 'json',
                'before_status' => 'int',
                'after_status' => 'int',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
