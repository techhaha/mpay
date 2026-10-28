<?php
declare(strict_types=1);

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\common\constant\PaymentResultCode as Result;
use app\model\payment\PayCallbackLog;
use InvalidArgumentException;
use think\db\Query;

/** 所有来源共用的收款回调记录；成功审计与交易一起提交，正文只保留最终匹配项。 */
final class PayCallbackLogRepository extends BaseRepository
{
    private const LIST_FIELDS = 'id,pay_no,channel_id,plugin_code,request_id,event_key,amount,paid_at,received_at,result_status,result_code,message,payload_truncated,created_at';

    public function __construct(PayCallbackLog $model)
    {
        parent::__construct($model);
    }

    public function find(string $id): ?PayCallbackLog
    {
        return $this->model->find($id);
    }

    /** @return array{items:list<PayCallbackLog>,total:int} 正文、摘要及扩展仅在详情读取。 */
    public function adminPage(array $filters, int $page, int $limit): array
    {
        $query = $this->query($filters);
        return [
            'total' => (int) (clone $query)->count(),
            'items' => $query
                ->field(self::LIST_FIELDS)
                ->order('received_at', 'desc')
                ->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->all(),
        ];
    }

    private function query(array $filters): Query
    {
        $query = $this->model->where('id', '>', 0);
        if (isset($filters['from'], $filters['until'])) {
            $query
                ->where('received_at', '>=', $filters['from'])
                ->where('received_at', '<', $filters['until']);
        }
        foreach (
            [
                'id',
                'pay_no',
                'channel_id',
                'plugin_code',
                'request_id',
                'result_status',
                'result_code',
            ]
            as $field
        ) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        return $query;
    }

    /**
     * @param array{channel_id:?string,plugin_code:?string,pay_no?:?string,request_id:string,
     *     received_at:string,result_status:int,result_code:string,message?:?string,event_key?:?string,
     *     amount?:?int,paid_at?:?string,payload_digest?:?string,payload_text?:?string,summary?:array} $data
     * 正文仅供查看；截断不影响原始验签、证据摘要及交易核验。失败记录不保存未匹配流水内容。
     */
    public function record(array $data): string
    {
        $this->requireTransaction();
        if (
            !preg_match('/^[a-f0-9]{32}$/D', $data['request_id']) ||
            !in_array($data['result_status'], [1, 2, 3, 4, 5], true)
        ) {
            throw new InvalidArgumentException('回调记录身份或结果无效');
        }
        $successful = in_array(
            $data['result_status'],
            [Result::CALLBACK_CONFIRMED, Result::CALLBACK_REPLAYED],
            true,
        );
        $payload = $successful ? $data['payload_text'] ?? null : null;
        if ($payload !== null) {
            $payload = mb_scrub($payload, 'UTF-8');
        }
        $truncated = $payload !== null && strlen($payload) > 8192;
        return $this->insert(
            [
                'channel_id' => $data['channel_id'],
                'plugin_code' => $data['plugin_code'],
                'pay_no' => $data['pay_no'] ?? null,
                'request_id' => $data['request_id'],
                'received_at' => $data['received_at'],
                'event_key' => $data['event_key'] ?? null,
                'amount' => $successful ? $data['amount'] ?? null : null,
                'paid_at' => $successful ? $data['paid_at'] ?? null : null,
                'result_status' => $data['result_status'],
                'result_code' => $data['result_code'],
                'message' => mb_strcut(
                    $data['message'] ?? Result::label($data['result_code']),
                    0,
                    512,
                    'UTF-8',
                ),
                'payload_digest' => $data['payload_digest'] ?? null,
                'payload_text' => $payload === null ? null : mb_strcut($payload, 0, 8192, 'UTF-8'),
                'payload_truncated' => (int) $truncated,
                'summary_json' => isset($data['summary'])
                    ? json_encode($data['summary'], JSON_THROW_ON_ERROR)
                    : null,
            ],
            [
                'channel_id',
                'plugin_code',
                'pay_no',
                'request_id',
                'received_at',
                'event_key',
                'amount',
                'paid_at',
                'result_status',
                'result_code',
                'message',
                'payload_digest',
                'payload_text',
                'payload_truncated',
                'summary_json',
            ],
        );
    }
}
