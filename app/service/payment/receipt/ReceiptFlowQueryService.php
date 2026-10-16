<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\util\Money;
use app\exception\BusinessException;
use app\model\payment\ReceiptFlow;
use app\model\payment\PayOrder;
use app\repository\payment\receipt\ReceiptFlowRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\PayCallbackLogRepository;
use app\repository\payment\trade\ChannelNotifyLogRepository;

/**
 * 到账及接收审计的只读视图；每条已保存流水按 pay_no 读取最终订单，不猜测所属订单。
 * @phpstan-type ReceiptView array{id:string,scope_key:string,plugin_code:string,
 *     reason_code:?string,reason_label:string,amount:string,paid_at:?string,received_at:string,
 *     platform_trade_no:?string,pay_no:string,biz_no:?string}
 */
final class ReceiptFlowQueryService extends BaseService
{
    public function __construct(
        private readonly ReceiptFlowRepository $flows,
        private readonly PayOrderRepository $orders,
        private readonly PayCallbackLogRepository $callbacks,
        private readonly ChannelNotifyLogRepository $ingress,
    ) {}

    /**
     * @param array{from?:string,until?:string,id?:string,scope_key?:string,plugin_code?:string,pay_no?:string} $filters 日期缺省时查询全部已确认流水。
     * @return array{items:list<ReceiptView>,total:int,page:int,limit:int}
     */
    public function listing(array $filters, int $page, int $limit): array
    {
        return $this->transaction(function () use ($filters, $page, $limit): array {
            $data = $this->flows->adminPage($filters, $page, $limit);
            $numbers = array_map(
                static fn(ReceiptFlow $flow): string => $flow['pay_no'],
                $data['items'],
            );
            $payments = $this->orders->forNumbers($numbers);
            $items = [];
            foreach ($data['items'] as $flow) {
                $items[] = $this->summary($flow, $payments[$flow['pay_no']] ?? null);
            }
            return [
                'items' => $items,
                'total' => $data['total'],
                'page' => $page,
                'limit' => $limit,
            ];
        });
    }

    /** @return array<string,mixed> 插件身份和时间可信度沿用首次保存值，不用当前配置改写到账事实。 */
    public function detail(string $id): array
    {
        return $this->transaction(function () use ($id): array {
            $flow = $this->flow($id);
            $result = $this->summary($flow, $this->orders->find($flow['pay_no']));
            $proof = is_array($flow['evidence_json']) ? $flow['evidence_json'] : [];
            $reference = is_array($proof['source_reference'] ?? null)
                ? $proof['source_reference']
                : [];
            foreach (['plugin_code', 'plugin_version'] as $field) {
                $value = $reference[$field] ?? null;
                $result[$field] = is_string($value) && strlen($value) <= 64 ? $value : null;
            }
            $configuration = $reference['api_config_id'] ?? null;
            $revision = $reference['config_revision_id'] ?? null;
            $digest = $proof['source_payload_digest'] ?? null;
            $result['api_config_id'] =
                is_string($configuration) && preg_match('/^[1-9][0-9]{0,19}$/D', $configuration)
                    ? $configuration
                    : null;
            $result['config_revision_id'] = is_int($revision) && $revision > 0 ? $revision : null;
            $result['source_payload_digest'] =
                is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) ? $digest : null;
            $result['event_key'] = $flow['event_key'];
            $result['payload_digest'] = $flow['payload_digest'];
            $result['remark'] = $flow['remark'];
            return $result;
        });
    }

    /** @return array{items:list<array>,total:int,page:int,limit:int} 返回首次确认及幂等重报的审计，不输出整个 summary_json。 */
    public function history(string $id, int $page, int $limit): array
    {
        return $this->transaction(function () use ($id, $page, $limit): array {
            $this->flow($id);
            $data = $this->callbacks->pageForFlow($id, $page, $limit);
            $items = [];
            foreach ($data['items'] as $record) {
                $items[] = [
                    'id' => $record['id'],
                    'request_id' => $record['request_id'],
                    'created_at' => $record['created_at'],
                    'pay_no' => $record['pay_no'],
                    'result_code' => $this->reasonCode($record['result_code']),
                    'result_label' => $this->reasonLabel($this->reasonCode($record['result_code'])),
                ];
            }
            return [
                'items' => $items,
                'total' => $data['total'],
                'page' => $page,
                'limit' => $limit,
            ];
        });
    }

    /**
     * 解析/来源拒绝可能没有 ReceiptFlow；仍可在接收审计中按通道、请求号定位原因和报文摘要。
     * @param array{from:string,until:string,channel_id?:string,plugin_code?:string,result_code?:string,request_id?:string} $filters
     * @return array{items:list<array>,total:int,page:int,limit:int}
     */
    public function ingress(array $filters, int $page, int $limit): array
    {
        return $this->transaction(function () use ($filters, $page, $limit): array {
            $data = $this->ingress->adminPage($filters, $page, $limit);
            $items = [];
            foreach ($data['items'] as $record) {
                $count = $record['summary_json']['count'] ?? null;
                $items[] = [
                    'id' => $record['id'],
                    'channel_id' => $record['channel_id'],
                    'plugin_code' => $record['plugin_code'],
                    'request_id' => $record['request_id'],
                    'result_code' => $this->reasonCode($record['result_code']),
                    'payload_digest' => $record['payload_digest'],
                    'result_label' => $this->reasonLabel($this->reasonCode($record['result_code'])),
                    'created_at' => $record['created_at'],
                    'flow_count' => is_int($count) && $count >= 0 ? $count : null,
                ];
            }
            return [
                'items' => $items,
                'total' => $data['total'],
                'page' => $page,
                'limit' => $limit,
            ];
        });
    }

    /** @return array{items:list<array{id:string}>,total:int,page:int,limit:int} 一个接收请求的全部持久流水引用可分页查看。 */
    public function ingressFlows(string $id, int $page, int $limit): array
    {
        $record =
            $this->ingress->find($id) ??
            throw new BusinessException('接收记录不存在', ApiResponseConstant::NOT_FOUND);
        $ids = $record['summary_json']['flow_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new \RuntimeException('接收记录缺少完整流水引用');
        }
        foreach ($ids as $value) {
            if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,19}$/D', $value)) {
                throw new \RuntimeException('接收记录流水引用无效');
            }
        }
        return [
            'items' => array_map(
                static fn(string $value): array => ['id' => $value],
                array_slice($ids, ($page - 1) * $limit, $limit),
            ),
            'total' => count($ids),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    private function flow(string $id): ReceiptFlow
    {
        return $this->flows->find($id) ??
            throw new BusinessException('到账记录不存在', ApiResponseConstant::NOT_FOUND);
    }

    /** @return ReceiptView 与订单详情共用公开投影，不另开事务；ID 保留字符串，amount 为元字符串。 */
    public function summary(ReceiptFlow $flow, ?PayOrder $pay): array
    {
        if ($pay !== null && $pay['receipt_scope_key'] !== $flow['receipt_scope_key']) {
            throw new \RuntimeException('流水绑定指向其他收款范围');
        }
        return [
            'id' => $flow['id'],
            'scope_key' => $flow['receipt_scope_key'],
            'plugin_code' => $flow['plugin_code'],
            'reason_code' => $this->reasonCode($flow['reason_code']),
            'reason_label' => $this->reasonLabel($this->reasonCode($flow['reason_code'])),
            'amount' => Money::format((int) $flow['amount']),
            'paid_at' => $flow['paid_at'],
            'received_at' => $flow['received_at'],
            'platform_trade_no' => $flow['platform_trade_no'],
            'pay_no' => $flow['pay_no'],
            'biz_no' => $pay === null ? null : $pay['biz_no'],
        ];
    }

    /** 原始异常文本不能被误当作固定原因码返回；保留未知标记，不把坏数据解释成等待处理。 */
    private function reasonCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) ? $value : 'unknown';
    }

    /** 不把技术拒绝解释成没有付款；未知原因要求核对，不生成自动改状态建议。 */
    public function reasonLabel(?string $reason): string
    {
        return \app\common\constant\PaymentResultCode::label($reason);
    }
}
