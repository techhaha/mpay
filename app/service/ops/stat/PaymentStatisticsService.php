<?php
declare(strict_types=1);

namespace app\service\ops\stat;

use app\common\base\BaseService;
use app\common\constant\TradeConstant;
use app\common\constant\NotifyConstant;
use app\common\util\Money;
use app\repository\ops\PaymentStatisticsRepository;
use app\service\system\runtime\RuntimeStatusService;

/**
 * 工作台金额和成功率的明确统计口径；到账消息数、通知重试数和设备心跳均不折算为业务成交。
 * @phpstan-type DayStatistics array{date:string,timezone:string,created_orders:int,created_successes:int,
 *     created_success_rate:?string,paid_orders:int,business_amount:string,received_amount:string,received_difference:string,
 *     requested_offset:string,matched_receipts:int,notification_counts:list<int>,unknown_state_records:int,
 *     observed_at:string,runtime:array,device_status:string}
 */
final class PaymentStatisticsService extends BaseService
{
    public function __construct(
        private readonly PaymentStatisticsRepository $statistics,
        private readonly RuntimeStatusService $runtime,
    ) {}

    /**
     * 收款按 paid_at 所属日期，成功率按该日创建订单截至当前的成功状态；积压和心跳是当前快照。
     * @param array{from:string,until:string,start_date:string,end_date:string} $range 入口已限定为同一自然日。
     * @return DayStatistics 金额是精确元字符串；无创建订单时成功率为 null。
     */
    public function day(array $range): array
    {
        $result = $this->transaction(function () use ($range): array {
            $raw = $this->statistics->day(
                $range['from'],
                $range['until'],
                $range['app_id'] ?? null,
            );
            $business = $raw['business'];
            $payments = $raw['payments'];
            if (
                (int) $business['records'] !== (int) $payments['records'] ||
                (int) $payments['records'] !== (int) $payments['with_amount'] ||
                (string) $business['amount'] !== (string) $payments['sum_business_cents']
            ) {
                throw new \RuntimeException('成功订单的业务金额或实收统计不一致');
            }
            $created = array_sum($raw['created']);
            $success = $raw['created'][TradeConstant::ORDER_STATUS_SUCCESS] ?? 0;
            $known = [
                TradeConstant::ORDER_STATUS_CREATED,
                TradeConstant::ORDER_STATUS_PAYING,
                TradeConstant::ORDER_STATUS_SUCCESS,
                TradeConstant::ORDER_STATUS_FAILED,
                TradeConstant::ORDER_STATUS_CLOSED,
                TradeConstant::ORDER_STATUS_TIMEOUT,
            ];
            if (array_diff(array_keys($raw['created']), $known) !== []) {
                throw new \RuntimeException('统计包含未知业务状态');
            }
            return [
                'app_id' => $range['app_id'] ?? null,
                'date' => $range['start_date'],
                'timezone' => 'Asia/Shanghai',
                'created_orders' => $created,
                'created_successes' => $success,
                'created_success_rate' =>
                    $created === 0 ? null : number_format(($success / $created) * 100, 2, '.', ''),
                'paid_orders' => (int) $business['records'],
                'business_amount' => Money::formatTotal((string) $business['amount']),
                'received_amount' => Money::formatTotal((string) $payments['sum_received_cents']),
                'received_difference' => Money::formatTotal(
                    (string) $payments['sum_difference_cents'],
                ),
                'requested_offset' => Money::formatTotal((string) $payments['sum_offset_cents']),
                'matched_receipts' => $raw['matched_receipts'],
                // 通知计数固定按NotifyConstant::STATUSES 的顺序输出五项，空集与非空集保持同一种 JSON 形状。
                'notification_counts' => array_replace(
                    array_fill_keys(NotifyConstant::STATUSES, 0),
                    array_intersect_key(
                        $raw['notifications'],
                        array_flip(NotifyConstant::STATUSES),
                    ),
                ),
                'unknown_state_records' => array_sum(
                    array_diff_key($raw['notifications'], array_flip(NotifyConstant::STATUSES)),
                ),
            ];
        });
        // 文件读取在数据库事务外；observed_at 仅供展示，不参与统计口径或交易判断。
        // 平台在线状态始终未知，不能从某条心跳推断。
        return $result + [
            'observed_at' => date('Y-m-d H:i:s'),
            'runtime' => $this->runtime->workers(),
            'device_status' => 'unknown',
        ];
    }
}
