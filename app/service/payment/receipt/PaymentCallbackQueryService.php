<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\constant\PaymentResultCode as Result;
use app\common\util\Money;
use app\exception\BusinessException;
use app\model\payment\PayCallbackLog;
use app\model\payment\PayOrder;
use app\repository\payment\receipt\ReceiptFlowRepository;
use app\repository\payment\trade\PayCallbackLogRepository;
use app\repository\payment\trade\PayOrderRepository;
use RuntimeException;

/** 统一收款回调只读入口；列表读取小投影，通知正文与成功证据仅在详情读取。 */
final class PaymentCallbackQueryService extends BaseService
{
    public function __construct(
        private readonly PayCallbackLogRepository $callbacks,
        private readonly PayOrderRepository $orders,
        private readonly ReceiptFlowRepository $flows,
    ) {}

    public function listing(array $filters, int $page, int $limit): array
    {
        return $this->transaction(
            fn(): array => $this->listingInTransaction($filters, $page, $limit),
        );
    }

    /** @internal 订单历史已核对业务单，在原只读事务内复用查询，不另开事务。 */
    public function listingInTransaction(array $filters, int $page, int $limit): array
    {
        $this->requireTransaction();
        $data = $this->callbacks->adminPage($filters, $page, $limit);
        $numbers = array_values(
            array_filter(
                array_map(static fn(PayCallbackLog $row) => $row['pay_no'], $data['items']),
            ),
        );
        $payments = $this->orders->forNumbers($numbers);
        return [
            'items' => array_map(
                fn(PayCallbackLog $row) => $this->summary(
                    $row,
                    $payments[$row['pay_no'] ?? ''] ?? null,
                ),
                $data['items'],
            ),
            'total' => $data['total'],
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function detail(string $id): array
    {
        return $this->transaction(function () use ($id): array {
            $record =
                $this->callbacks->find($id) ??
                throw new BusinessException('收款回调记录不存在', ApiResponseConstant::NOT_FOUND);
            $pay = $record['pay_no'] === null ? null : $this->orders->find($record['pay_no']);
            $view = $this->summary($record, $pay) + [
                'payload_text' => $record['payload_text'],
                'payload_digest' => $record['payload_digest'],
                'before_status' => $record['summary_json']['before_status'] ?? null,
                'evidence' => null,
            ];
            if (
                in_array(
                    (int) $record['result_status'],
                    [Result::CALLBACK_CONFIRMED, Result::CALLBACK_REPLAYED],
                    true,
                ) &&
                $record['event_key'] !== null &&
                $pay !== null &&
                $pay['receipt_flow_id'] !== null
            ) {
                $flow = $this->flows->find($pay['receipt_flow_id']);
                if (
                    $flow === null ||
                    $flow['pay_no'] !== $pay['pay_no'] ||
                    $flow['event_key'] !== $record['event_key'] ||
                    $flow['receipt_scope_key'] !== $pay['receipt_scope_key']
                ) {
                    throw new RuntimeException('成功流水与原支付单不一致');
                }
                $view['evidence'] = [
                    'amount' => Money::format((int) $flow['amount']),
                    'paid_at' => $flow['paid_at'],
                    'received_at' => $flow['received_at'],
                    'event_key' => $flow['event_key'],
                    'platform_trade_no' => $flow['platform_trade_no'],
                ];
            }
            return $view;
        });
    }

    private function summary(PayCallbackLog $row, ?PayOrder $pay): array
    {
        return [
            'id' => $row['id'],
            'channel_id' => $row['channel_id'],
            'plugin_code' => $row['plugin_code'],
            'pay_no' => $row['pay_no'],
            'biz_no' => $pay?->getAttr('biz_no'),
            'request_id' => $row['request_id'],
            'event_key' => $row['event_key'],
            'amount' => $row['amount'] === null ? null : Money::format((int) $row['amount']),
            'paid_at' => $row['paid_at'],
            'received_at' => $row['received_at'],
            'result_status' => (int) $row['result_status'],
            'result_label' => Result::callbackLabel((int) $row['result_status']),
            'result_code' => $row['result_code'],
            'reason_label' => Result::label($row['result_code']),
            'message' => $row['message'],
            'payload_truncated' => (bool) $row['payload_truncated'],
            'created_at' => $row['created_at'],
        ];
    }
}
