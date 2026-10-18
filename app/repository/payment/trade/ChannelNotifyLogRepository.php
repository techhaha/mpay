<?php
declare(strict_types=1);

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\model\payment\ChannelNotifyLog;

/**
 * 通道消息接收与解析定位的脱敏审计，不保存签名头、密钥或完整报文，不代替 ReceiptFlow 事实。
 *
 * @extends BaseRepository<ChannelNotifyLog>
 */
final class ChannelNotifyLogRepository extends BaseRepository
{
    public function __construct(ChannelNotifyLog $model)
    {
        parent::__construct($model);
    }

    /**
     * @param array{from:string,until:string,channel_id?:string,plugin_code?:string,result_code?:string,request_id?:string} $filters
     * @return array{items:list<ChannelNotifyLog>,total:int} 接收/解析拒绝不一定产生到账事实，因此有独立只读入口。
     */
    public function adminPage(array $filters, int $page, int $limit): array
    {
        $query = $this->model
            ->where('created_at', '>=', $filters['from'])
            ->where('created_at', '<', $filters['until']);
        foreach (['channel_id', 'plugin_code', 'result_code', 'request_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        return [
            'total' => (int) (clone $query)->count(),
            'items' => $query->order('id', 'desc')->page($page, $limit)->select()->all(),
        ];
    }

    /**
     * 调用方拥有短审计事务；网络/来源解析已经结束，不能在本次事务中再回到订单锁。
     *
     * @param string|null $flowId 本次已持久化的流水 ID；未确认流水时为 null。
     */
    public function record(
        string $channelId,
        string $requestId,
        string $pluginCode,
        string $result,
        string $digest,
        ?string $flowId,
    ): void {
        if (
            !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $result) ||
            !preg_match('/^[a-f0-9]{64}$/D', $digest)
        ) {
            throw new \InvalidArgumentException('通道审计只接受固定结果码和摘要');
        }
        $this->insert(
            [
                'channel_id' => $channelId,
                'request_id' => $requestId,
                'plugin_code' => $pluginCode,
                'result_code' => $result,
                'payload_digest' => $digest,
                'summary_json' => json_encode(
                    ['flow_id' => $flowId],
                    JSON_THROW_ON_ERROR,
                ),
            ],
            [
                'channel_id',
                'request_id',
                'plugin_code',
                'result_code',
                'payload_digest',
                'summary_json',
            ],
        );
    }
}
