<?php
declare(strict_types=1);

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\model\payment\PayCallbackLog;
use InvalidArgumentException;

/**
 * 回调处理的持久审计；与订单/流水处理结论一起提交，不用可降级技术日志替代。
 *
 * @extends BaseRepository<PayCallbackLog>
 */
final class PayCallbackLogRepository extends BaseRepository
{
    /** @return array{items:list<PayCallbackLog>,total:int} 按最终 flow_id 精确关联成功与幂等重报历史，未匹配输入不生成流水。 */
    public function pageForFlow(string $flowId, int $page, int $limit): array
    {
        $query = $this->model->where('summary_json->flow_id', $flowId);
        return [
            'total' => (int) (clone $query)->count(),
            'items' => $query->order('id', 'desc')->page($page, $limit)->select()->all(),
        ];
    }

    /** @return array{items:list<PayCallbackLog>,total:int} 回调/执行历史由查询服务脱敏映射，原始 summary 不直接进入后台。 */
    public function pageForPay(string $payNo, int $page, int $limit): array
    {
        return [
            'items' => $this->model
                ->where('pay_no', $payNo)
                ->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->all(),
            'total' => (int) $this->model->where('pay_no', $payNo)->count(),
        ];
    }

    public function __construct(PayCallbackLog $model)
    {
        parent::__construct($model);
    }

    /**
     * @param string|null $payNo 关联支付单编号；到账确认由调用方提供流水最终绑定的 pay_no，审计层不选择订单。
     * @param array{flow_id:?string,
     *     config_version?:int,before_status?:int,
     *     source_reference?:array{plugin_code:string,plugin_version:string,api_config_id:string,config_revision_id:int}
     * } $summary 只接收脱敏核对标识，来源引用不包含配置正文或秘密。
     */
    public function record(
        ?string $payNo,
        string $requestId,
        string $eventKey,
        string $digest,
        string $result,
        array $summary,
    ): void {
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
            throw new InvalidArgumentException('回调关联号必须由服务端生成');
        }
        $this->insert(
            [
                'pay_no' => $payNo,
                'request_id' => $requestId,
                'event_key' => $eventKey,
                'payload_digest' => $digest,
                'result_code' => $result,
                'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
            ],
            ['pay_no', 'request_id', 'event_key', 'payload_digest', 'result_code', 'summary_json'],
        );
    }

    /**
     * 执行认领/回写只记固定原因、原状态和 token 摘要；不伪造平台事件键或记录异常正文。
     * 与执行状态共用事务，旧工作者被拒绝也留下审计，不能仅靠技术日志判断是否已调用插件。
     */
    public function recordExecution(
        string $payNo,
        string $requestId,
        string $token,
        string $reason,
        int $beforeStatus,
    ): void {
        if (
            !preg_match('/^[a-f0-9]{32}$/D', $requestId) ||
            !preg_match('/^[a-f0-9]{32}$/D', $token)
        ) {
            throw new InvalidArgumentException('执行关联号必须由服务端生成');
        }
        $this->insert(
            [
                'pay_no' => $payNo,
                'request_id' => $requestId,
                'event_key' => null,
                'payload_digest' => null,
                'result_code' => $reason,
                'summary_json' => json_encode(
                    [
                        'execution_token_digest' => hash('sha256', $token),
                        'before_status' => $beforeStatus,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ],
            ['pay_no', 'request_id', 'event_key', 'payload_digest', 'result_code', 'summary_json'],
        );
    }
}
