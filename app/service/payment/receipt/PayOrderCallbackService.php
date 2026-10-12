<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\constant\PaymentResultCode as Result;
use app\common\constant\TradeConstant;
use app\common\interface\ChannelNotifyInterface;
use app\common\interface\ReceiptCollectorInterface;
use app\common\util\Clock;
use app\common\validation\PluginConfigValidator;
use app\exception\BusinessException;
use app\exception\ReceiptCollectionException;
use app\exception\UnsupportedPaymentOperationException;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\receipt\ReceiptFlowRepository;
use app\repository\payment\trade\ChannelNotifyLogRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\plugin\PaymentPluginNotifyResultValidator;
use DateTimeImmutable;
use RuntimeException;
use think\Request;
use think\Response;
use Throwable;
use UnexpectedValueException;

/**
 * 消息/采集入口共用的回调编排：插件定位及解析 → 服务校验 → 生命周期核验 → 成功与最终流水原子提交。
 * 来源验签由平台适配器完成，不能把本服务的方法暴露成匿名内部到账接口。
 */
final class PayOrderCallbackService extends BaseService
{
    public function __construct(
        private readonly PayOrderLifecycleService $lifecycle,
        private readonly PaymentChannelRepository $channels,
        private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentCatalogRepository $catalog,
        private readonly ReceiptScopeService $scopeAccess,
        private readonly PaymentPluginFactoryService $plugins,
        private readonly PluginConfigValidator $validator,
        private readonly ChannelNotifyLogRepository $channelLogs,
        private readonly Clock $clock,
        private readonly PayOrderRepository $orders,
        private readonly ReceiptFlowRepository $flows,
        private readonly ReceiptRecordValidator $records,
        private readonly PaymentPluginNotifyResultValidator $results,
    ) {
    }

    /**
     * 通道消息入口只接收原始报文和路由选择器；账号、配置和范围全部从服务器读取。
     * 插件一次完成验签、解析和定位，服务核对归属后提交匹配；协议 ACK 本身不代表订单成功。
     *
     * @return array{status:int,content_type:string,body:string} 由来源 HTTP 适配器原样转成协议响应。
     * @throws BusinessException 通道未知、来源不可用、验签或消息解析失败。
     */
    public function receiveChannel(string $channelId, Request $request, string $requestId): array
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $channelId)
            || !preg_match('/^[a-f0-9]{32}$/D', $requestId) || strlen($request->getInput()) > 65536) {
            throw new BusinessException('来源入口参数无效', ApiResponseConstant::BAD_REQUEST);
        }
        $channel = $this->channels->find($channelId) ?? throw new BusinessException('来源通道不存在', ApiResponseConstant::NOT_FOUND);
        $pluginCode = $channel['plugin_code'];
        $digest = hash('sha256', $request->method(true) . "\n" . $request->server('QUERY_STRING', '') . "\n" . $request->getInput());
        $flowIds = [];
        try {
            $context = $this->channelContext($channelId, $channel['api_config_id']);
            $plugin = $this->plugins->initialize($context->pluginCode, $context->configuration,
                ['channel_id' => $channelId, 'api_config_id' => $context->configurationId,
                    'receipt_scope_key' => $channel['receipt_scope_key']]);
            if (!$plugin instanceof ChannelNotifyInterface) { throw new UnsupportedPaymentOperationException(); }
            // 对同一请求只解析、定位一次，避免二次读取候选时跨越订单过期或并发成功边界。
            $result = $this->results->validateResult($plugin->notify($request));
            $accepted = $this->acceptPluginResult($result, $context, $requestId);
            if (!$accepted['matched']) { throw new BusinessException('通知未匹配到符合收款条件的订单', ApiResponseConstant::CONFLICT); }
            $flowIds[] = $accepted['flow_id'];
            $ack = $this->acknowledgement($plugin->notifySuccess(), true);
        } catch (Throwable $error) {
            $this->recordChannel($channelId, $requestId, $pluginCode, $error instanceof BusinessException ? Result::CHANNEL_REJECTED : Result::CHANNEL_FAULT, $digest, $flowIds);
            if ($error instanceof BusinessException && isset($plugin) && $plugin instanceof ChannelNotifyInterface) {
                // 业务拒绝按原插件协议回应；未知通道和程序故障仍交全局处理，不能伪装已接收。
                return $this->acknowledgement($plugin->notifyFail(), false, ApiResponseConstant::httpStatus($error->getCode()));
            }
            throw $error;
        }
        $this->recordChannel($channelId, $requestId, $pluginCode, Result::CHANNEL_ACCEPTED, $digest, $flowIds);
        return $ack;
    }

    /** 插件可提供平台要求的 Response；字符串失败应答保留原业务 HTTP 状态。 */
    private function acknowledgement(string|Response $reply, bool $success, int $failureStatus = 400): array
    {
        $ack = $reply instanceof Response
            ? ['status' => $reply->getCode(), 'content_type' => $reply->getHeader('Content-Type') ?: 'text/plain; charset=utf-8', 'body' => $reply->getContent()]
            : ['status' => $success ? 200 : $failureStatus, 'content_type' => 'text/plain; charset=utf-8', 'body' => $reply];
        if ($ack['status'] < 200 || $ack['status'] > ($success ? 299 : 599)
            || ($ack['status'] >= 300 && $ack['status'] <= 399)
            || strlen($ack['body']) > 4096 || !mb_check_encoding($ack['body'], 'UTF-8')
            || !in_array($ack['content_type'], ['text/plain; charset=utf-8', 'text/html; charset=utf-8', 'application/json; charset=utf-8', 'application/xml; charset=utf-8'], true)
            || (in_array($ack['status'], [204, 205], true) && $ack['body'] !== '')) {
            throw new UnexpectedValueException('插件 ACK 无效');
        }
        return $ack;
    }

    /**
     * 本次单页响应整体匹配，先批量查询已消费事件，防止旧流水被分给新订单。
     * 校验全部结果的来源后逐笔提交；任何已提交结果由数据库唯一约束防重，不依赖缓存。
     * @param array<string,array> $channels 本次查询实际覆盖的通道版本。
     * @param array<string,array> $orders 服务读取的原单快照，不接受插件新增候选。
     * @param list<array> $records 最多 200 条归一化流水。
     * @return array{matched:int,ignored:int}
     */
    public function receiveCollected(ReceiptCollectorInterface $plugin, array $channels, array $orders, array $records, string $requestId): array
    {
        if (!array_is_list($records) || count($records) > 200) { throw new UnexpectedValueException('采集批次数量无效'); }
        $evidence = []; $trades = [];
        foreach ($records as $raw) {
            $record = $this->records->validateRecord($raw);
            $key = $record['event_key'];
            if (isset($evidence[$key]) && $evidence[$key]['payload_digest'] !== $record['payload_digest']) {
                throw new ReceiptCollectionException('protocol_error');
            }
            $evidence[$key] = $record;
            if ($record['trade_no'] !== null) { $trades[] = $record['trade_no']; }
        }
        $events = []; $usedTrades = [];
        foreach ($this->flows->consumed($plugin->getCode(), array_keys($evidence), $trades) as $flow) {
            $events[$flow['event_key']] = $flow['pay_no'];
            if ($flow['platform_trade_no'] !== null) { $usedTrades[$flow['platform_trade_no']] = $flow['pay_no']; }
        }
        $batch = [];
        foreach ($evidence as $record) {
            $batch[] = ['record' => $record, 'consumed_pay_no' => $events[$record['event_key']] ?? $usedTrades[$record['trade_no'] ?? ''] ?? null];
        }
        $results = $plugin->matchReceipts($batch, $orders, $channels);
        if (!array_is_list($results) || count($results) > count($batch)) { throw new UnexpectedValueException('插件批量匹配结果无效'); }
        $selected = []; $selectedEvents = [];
        foreach ($results as &$result) {
            $result = $this->results->validateResult($result);
            $record = $this->records->validateRecord($result['receipt']);
            $pay = $orders[$result['pay_no']] ?? null;
            if ($pay === null || !isset($channels[$pay['channel_revision_id']]) || isset($selected[$result['pay_no']])
                || isset($selectedEvents[$record['event_key']]) || isset($events[$record['event_key']])
                || isset($usedTrades[$record['trade_no'] ?? ''])
                || ($evidence[$record['event_key']]['payload_digest'] ?? null) !== $record['payload_digest']) {
                throw new UnexpectedValueException('插件返回批次外证据、已消费流水或重复订单');
            }
            $selected[$result['pay_no']] = true;
            $selectedEvents[$record['event_key']] = true;
        }
        unset($result);
        $matched = 0;
        foreach ($results as $result) {
            $pay = $orders[$result['pay_no']];
            $channel = $channels[$pay['channel_revision_id']];
            $context = new ChannelNotifyContext($channel['channel_id'], $channel['api_config_id'],
                $channel['config_version'], $plugin->getCode(), $plugin->getVersion(),
                new ReceiptScopeSet([$channel['receipt_scope_key'] => ['pay_type' => $pay['pay_type']]]),
                $channel['configuration'], $this->clock->now());
            $matched += (int) $this->acceptPluginResult($result, $context, $requestId)['matched'];
        }
        return ['matched' => $matched, 'ignored' => count($records) - $matched];
    }

    /**
     * 把插件标准数组转换为核心内部证据，并核对服务端授权；插件不构造 ReceiptEvent。
     *
     * @param array<string,mixed> $result 已解析的平台结果；服务在此复核，不信任插件声称的订单归属。
     * @return array{flow_id:?string,pay_no:?string,matched:bool,reason:string}
     */
    public function acceptPluginResult(array $result, ChannelNotifyContext $context, string $requestId): array
    {
        $result = $this->results->validateResult($result);
        $record = $this->records->validateRecord($result['receipt']);
        $pay = $this->orders->find($result['pay_no']) ?? throw new BusinessException('通知支付单不存在', ApiResponseConstant::NOT_FOUND);
        $channel = $this->channels->find($pay['channel_id']);
        $scope = $context->scopes->entries[$pay['receipt_scope_key']] ?? null;
        // 首次自动确认仍须命中识别金额；原成功交易的重报/补证据只核对已确认实收。
        // 这里是入口校验，最终关联及幂等结论仍由生命周期在锁内复核。
        $confirmed = (int) $pay['status'] === TradeConstant::ORDER_STATUS_SUCCESS;
        $expectedAmount = $confirmed ? $pay->receivedAmount() : $pay->receiptAmount();
        $sameEvent = ($pay['ext_json']['personal_receipt']['event_key'] ?? null) === $record['event_key'];
        if ($channel === null || $pay->channelRevision['api_config_id'] !== $context->configurationId
            || ($context->channelId !== null && $pay['channel_id'] !== $context->channelId)
            || $scope === null || $scope['pay_type'] !== $record['pay_type'] || $pay['plugin_code'] !== $context->pluginCode
            || $result['paid_amount'] !== $pay->originalAmount() || $record['amount'] !== $expectedAmount
            || ($confirmed && ($pay['channel_trade_no'] !== $record['trade_no'] || ($record['trade_no'] === null && !$sameEvent)))
            || ($result['chan_trade_no'] ?? null) !== $record['trade_no']) {
            throw new BusinessException('插件通知与订单金额或归属不一致', ApiResponseConstant::FORBIDDEN);
        }
        $paidAt = $record['paid_at'] === null ? null : new DateTimeImmutable($record['paid_at']);
        $event = new ReceiptEvent($pay['receipt_scope_key'],
            $context->configurationVersion, $record['pay_type'], $record['event_key'], $record['amount'],
            $paidAt, $record['trade_no'], $record['remark'], $record['payload_digest'],
            $context->pluginCode, $context->pluginVersion, $context->configurationId, $pay['pay_no']);
        return $this->lifecycle->confirmReceipt($event, $context->receivedAt, $requestId);
    }

    /**
     * 配置 → 通道 → 插件 → 范围的短事务生成授权快照；停用新单通道不改已有收款来源事实。
     * 配置开关限制新业务单，不阻断已有测试/业务单的到账；当前配置归属、版本及插件验签仍须通过。
     */
    private function channelContext(string $channelId, string $configurationId): ChannelNotifyContext
    {
        return $this->transaction(function () use ($channelId, $configurationId): ChannelNotifyContext {
            $configuration = $this->configurations->lock($configurationId);
            $channel = $this->channels->lock($channelId);
            if ($channel['api_config_id'] !== $configurationId
                || $channel['plugin_code'] !== $configuration['plugin_code']) {
                throw new BusinessException('来源配置不可用', ApiResponseConstant::FORBIDDEN);
            }
            $revision = $this->configurations->revision($configurationId, (int) $configuration['config_version'], true)
                ?? throw new RuntimeException('来源配置版本缺失');
            $definition = $this->plugins->definition($configuration['plugin_code']);
            if ($revision['config_json'] !== $configuration['config_json']) { throw new RuntimeException('账号配置正文不一致'); }
            $authorized = $this->scopeAccess->current($configuration, true);
            if (!isset($authorized[$channel['receipt_scope_key']])) {
                throw new BusinessException('该来源没有当前收款范围授权', ApiResponseConstant::FORBIDDEN);
            }
            foreach ($authorized as $scope) {
                if (!in_array($this->catalog->typeCode($scope['pay_type_id']), $definition->payTypes, true)) {
                    throw new BusinessException('该来源没有当前收款范围授权', ApiResponseConstant::FORBIDDEN);
                }
            }
            $settings = $this->validator->validate($definition->configSchema, $configuration['config_json']);
            return new ChannelNotifyContext($channelId, $configurationId, (int) $configuration['config_version'],
                $definition->code, $definition->version,
                $this->scopeAccess->describe($authorized),
                $settings, $this->clock->now());
        });
    }

    /** @param list<string> $flowIds 审计在自己的短事务中提交，失败不伪装 ACK，也不删除此前已提交的事实。 */
    private function recordChannel(string $channelId, string $requestId, string $pluginCode, string $result, string $digest, array $flowIds): void
    {
        $this->transaction(fn () => $this->channelLogs->record($channelId, $requestId, $pluginCode, $result, $digest, $flowIds));
    }

}
