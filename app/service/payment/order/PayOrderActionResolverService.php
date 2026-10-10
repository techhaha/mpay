<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\constant\NotifyConstant;
use app\common\constant\TradeConstant;
use app\model\payment\NotifyTask;
use app\model\payment\PayOrder;
use app\model\payment\BizOrder;

/** 计算当前页面可以提供的动作；只作展示提示，真正执行仍由动作服务在锁内校验身份、证据和状态。 */
final class PayOrderActionResolverService
{
    /** @return array{confirm:bool,confirm_unavailable_reason:?string,close:bool,retry_notification:bool,notification_action:string} 无支付尝试时不能凭页面创建虚构收款事实。 */
    public function resolve(BizOrder $biz, ?PayOrder $pay, ?NotifyTask $task): array
    {
        $status = $pay === null ? null : (int) $pay['status'];
        return [
            'confirm' => $pay !== null && in_array($status, [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING,
                TradeConstant::ORDER_STATUS_FAILED, TradeConstant::ORDER_STATUS_CLOSED, TradeConstant::ORDER_STATUS_TIMEOUT], true),
            'confirm_unavailable_reason' => $pay === null && (int) $biz['status'] !== TradeConstant::ORDER_STATUS_SUCCESS
                ? '尚未发起支付，无法补确认' : null,
            'notification_action' => $task === null ? '发送商户通知' : match ((int) $task['status']) {
                NotifyConstant::STATUS_HELD => '发送商户通知',
                NotifyConstant::STATUS_PENDING => '立即发送',
                NotifyConstant::STATUS_SUCCESS => '再次通知',
                default => '重新通知',
            },
            'close' => $pay === null ? (int) $biz['status'] === TradeConstant::ORDER_STATUS_CREATED
                : in_array($status, [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true),
            'retry_notification' => $status === TradeConstant::ORDER_STATUS_SUCCESS && $task !== null
                && in_array((int) $task['status'], [NotifyConstant::STATUS_PENDING, NotifyConstant::STATUS_HELD,
                    NotifyConstant::STATUS_SUCCESS, NotifyConstant::STATUS_EXHAUSTED], true) && $task['manual_attempt_no'] === null
                && $task['claim_token'] === null && $task['lease_until'] === null && (int) $task['retry_count'] < 4294967295
                && !str_starts_with((string) $task['last_response'], 'quarantined_'),
        ];
    }
}
