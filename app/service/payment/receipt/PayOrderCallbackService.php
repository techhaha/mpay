<?php
declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\constant\PaymentResultCode as Result;
use app\common\constant\TradeConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\ReceiptCollectorInterface;
use app\common\util\Clock;
use app\common\util\SafeLogger;
use app\exception\BusinessException;
use app\exception\ReceiptCollectionException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\receipt\ReceiptFlowRepository;
use app\repository\payment\trade\PayCallbackLogRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\config\PaymentChannelRevisionService;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\plugin\PaymentPluginNotifyResultValidator;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use think\db\exception\DbException;
use think\Request;
use think\Response;
use Throwable;
use UnexpectedValueException;

/**
 * 消息/采集入口共用的回调编排：插件定位及解析 → 服务校验 → 生命周期提交订单及通知责任。
 * 有 event_key 的结果核对并绑定真实流水；普通平台订单确认不制造流水。原文仅由插件 raw 提供。
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
        private readonly PayCallbackLogRepository $callbackLogs,
        private readonly Clock $clock,
        private readonly PayOrderRepository $orders,
        private readonly ReceiptFlowRepository $flows,
        private readonly ReceiptRecordValidator $records,
        private readonly PaymentPluginNotifyResultValidator $results,
        private readonly SafeLogger $logger,
        private readonly PaymentChannelRevisionService $revisions,
    ) {}

    /**
     * 通道消息入口只接收原始报文和路由选择器；账号、配置和范围全部从服务器读取。
     * 插件一次完成验签、解析和定位，服务核对归属后提交匹配；协议 ACK 本身不代表订单成功。
     *
     * @return string|Response 插件协议应答原样交给控制器；不拆解或重新构造响应。
     * @throws BusinessException 未知通道或插件装配前的入口错误；插件装配后的业务拒绝由 notifyFail 应答。
     */
    public function receiveChannel(
        string $identifier,
        Request $request,
        string $requestId,
    ): string|Response {
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
            throw new InvalidArgumentException('回调请求编号无效');
        }
        $notification = [
            'request_id' => $requestId,
            'channel_id' => null,
            'plugin_code' => null,
            'pay_no' => null,
            'received_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $plugin = null;
        try {
            if (
                !preg_match('/^(?:[1-9][0-9]{0,19}|P[0-9]{19})$/D', $identifier) ||
                strlen($request->getInput()) + strlen($request->url()) > 65536
            ) {
                throw new BusinessException('来源入口参数无效', ApiResponseConstant::BAD_REQUEST);
            }
            $pay = str_starts_with($identifier, 'P')
                ? $this->orders->find($identifier) ??
                    throw new BusinessException('来源支付单不存在', ApiResponseConstant::NOT_FOUND)
                : null;
            $channelId = $pay === null ? $identifier : $pay['channel_id'];
            // 支付号回调使用原单冻结配置，只有通道 ID 入口读取当前通道。
            $channel =
                $pay === null
                    ? $this->channels->find($channelId) ??
                        throw new BusinessException(
                            '来源通道不存在',
                            ApiResponseConstant::NOT_FOUND,
                        )
                    : null;
            $notification['channel_id'] = $channelId;
            $notification['plugin_code'] =
                $pay === null ? $channel['plugin_code'] : $pay['plugin_code'];
            $notification['pay_no'] = $pay === null ? null : $pay['pay_no'];
            $context =
                $pay === null
                    ? $this->channelContext($channelId, $channel['api_config_id'])
                    : $this->orderContext($pay);
            $plugin = $this->plugins->initialize($context->pluginCode, $context->configuration);
            $input = [
                'channel_id' => $channelId,
                'received_at' => $context->receivedAt->format('Y-m-d H:i:s'),
            ];
            if ($context->payNo !== null) {
                $input['pay_no'] = $context->payNo;
            }
            $results = $this->results->validateList(
                $plugin->notify($request, $input),
                $context->payNo,
            );
        } catch (DbException | PDOException $error) {
            throw $error;
        } catch (Throwable $error) {
            $this->recordFailure($notification, $error);
            return $this->failureReply($plugin, $error);
        }
        if ($results === []) {
            $this->recordDiagnostic(
                $notification,
                Result::CALLBACK_IGNORED,
                Result::CHANNEL_IGNORED,
            );
        }
        $failure = null;
        foreach ($results as $result) {
            try {
                $accepted = $this->confirmResult(
                    $result,
                    $context,
                    $requestId,
                    $this->notification($context, $requestId, $result),
                );
                if (!$accepted['matched']) {
                    throw new BusinessException(
                        '通知未匹配到符合收款条件的订单',
                        ApiResponseConstant::CONFLICT,
                    );
                }
            } catch (DbException | PDOException $error) {
                if ($failure !== null) {
                    $this->logger->exception($failure);
                }
                throw $error;
            } catch (Throwable $error) {
                if ($failure === null) {
                    $failure = $error;
                } else {
                    $this->logger->exception($error);
                }
            }
        }
        if ($failure !== null) {
            return $this->failureReply($plugin, $failure);
        }
        return $plugin->notifySuccess();
    }

    /**
     * 本次单页响应整体匹配，先批量查询已消费事件，防止旧流水被分给新订单。
     * 校验全部结果的来源后逐笔提交；任何已提交结果由数据库唯一约束防重，不依赖缓存。
     * @param array<string,array> $channels 本次查询实际覆盖的通道版本。
     * @param array<string,array> $orders 服务读取的原单快照，不接受插件新增候选。
     * @param list<array> $records 最多 200 条归一化流水。
     * @return int 本批经生命周期确认的笔数，供监听进程汇总运行状态。
     */
    public function receiveCollected(
        ReceiptCollectorInterface $plugin,
        array $channels,
        array $orders,
        array $records,
        string $requestId,
    ): int {
        $receivedAt = $this->clock->now();
        $channelIds = array_unique(array_column($channels, 'channel_id'));
        $batchNotification = [
            'channel_id' => count($channelIds) === 1 ? reset($channelIds) : null,
            'plugin_code' => $plugin->getCode(),
            'pay_no' => null,
            'request_id' => $requestId,
            'received_at' => $receivedAt->format('Y-m-d H:i:s'),
        ];
        try {
            if (!array_is_list($records) || count($records) > 200) {
                throw new UnexpectedValueException('采集批次数量无效');
            }
            if ($records === []) {
                return 0;
            }
            $evidence = [];
            $trades = [];
            foreach ($records as $raw) {
                $record = $this->records->validateRecord($raw);
                $key = $record['event_key'];
                if (
                    isset($evidence[$key]) &&
                    $evidence[$key]['payload_digest'] !== $record['payload_digest']
                ) {
                    throw new ReceiptCollectionException('protocol_error');
                }
                $evidence[$key] = $record;
                if ($record['trade_no'] !== null) {
                    $trades[] = $record['trade_no'];
                }
            }
            $events = [];
            $usedTrades = [];
            foreach (
                $this->flows->consumed($plugin->getCode(), array_keys($evidence), $trades)
                as $flow
            ) {
                $events[$flow['event_key']] = $flow['pay_no'];
                if ($flow['platform_trade_no'] !== null) {
                    $usedTrades[$flow['platform_trade_no']] = $flow['pay_no'];
                }
            }
            $batch = [];
            foreach ($evidence as $record) {
                $batch[] = [
                    'record' => $record,
                    'consumed_pay_no' =>
                        $events[$record['event_key']] ??
                        ($usedTrades[$record['trade_no'] ?? ''] ?? null),
                ];
            }
            $results = [];
            $notifiedChannels = [];
            foreach ($channels as $channel) {
                if (isset($notifiedChannels[$channel['channel_id']])) {
                    continue;
                }
                $notifiedChannels[$channel['channel_id']] = true;
                $instance = $this->plugins->initialize(
                    $plugin->getCode(),
                    $channel['configuration'],
                );
                // 候选直接复用本轮调度快照，不能按流水时间扩大到调度范围以外的旧单。
                // 全部候选供插件检查跨通道歧义；context 仅定位当前通道，不传其他通道配置。
                $items = $this->results->validateList(
                    $instance->notify(
                        [
                            'records' => $batch,
                            'orders' => $orders,
                        ],
                        [
                            'channel_id' => $channel['channel_id'],
                            'received_at' => $receivedAt->format('Y-m-d H:i:s'),
                        ],
                    ),
                );
                foreach ($items as $item) {
                    if (
                        ($orders[$item['pay_no']]['channel_id'] ?? null) !==
                        $channel['channel_id']
                    ) {
                        throw new UnexpectedValueException('采集通知返回当前通道以外的订单');
                    }
                    $results[] = $item;
                }
            }
            if (count($results) > count($batch)) {
                throw new UnexpectedValueException('插件批量匹配结果无效');
            }
            $selected = [];
            $selectedEvents = [];
            foreach ($results as $result) {
                if ($result['event_key'] === null) {
                    throw new UnexpectedValueException('采集匹配结果必须包含本批真实流水');
                }
                $record = $evidence[$result['event_key']] ?? null;
                $pay = $orders[$result['pay_no']] ?? null;
                if (
                    $pay === null ||
                    $record === null ||
                    !isset($channels[$pay['channel_revision_id']]) ||
                    isset($selected[$result['pay_no']]) ||
                    isset($selectedEvents[$result['event_key']]) ||
                    isset($events[$result['event_key']]) ||
                    isset($usedTrades[$result['chan_trade_no'] ?? '']) ||
                    $result['pay_type'] !== $record['pay_type'] ||
                    $result['received_amount'] !== $record['amount'] ||
                    $result['paid_at'] !==
                        ($record['paid_at'] ?? $receivedAt->format('Y-m-d H:i:s')) ||
                    $result['chan_trade_no'] !== $record['trade_no'] ||
                    $result['raw'] !== $record['raw']
                ) {
                    throw new UnexpectedValueException('插件返回批次外证据、已消费流水或重复订单');
                }
                $selected[$result['pay_no']] = true;
                $selectedEvents[$result['event_key']] = true;
            }
        } catch (DbException | PDOException $error) {
            throw $error;
        } catch (Throwable $error) {
            $this->recordFailure($batchNotification, $error);
            throw $error;
        }
        $matched = 0;
        foreach ($results as $result) {
            $pay = $orders[$result['pay_no']];
            $channel = $channels[$pay['channel_revision_id']];
            $context = new ChannelNotifyContext(
                $channel['channel_id'],
                $channel['api_config_id'],
                $channel['config_version'],
                $plugin->getCode(),
                $plugin->getVersion(),
                $channel['receipt_scope_key'],
                $pay['pay_type'],
                $channel['configuration'],
                $receivedAt,
            );
            try {
                $matched += (int) $this->confirmResult(
                    $result,
                    $context,
                    $requestId,
                    $this->notification($context, $requestId, $result),
                )['matched'];
            } catch (DbException | PDOException $error) {
                throw $error;
            } catch (Throwable $error) {
                // 本笔事务已结束；拒绝/故障不算匹配成功，也不能阻断批次里其他订单的独立确认。
                $this->logger->record(
                    'receipt.confirmation_rejected',
                    [
                        'pay_no' => $pay['pay_no'],
                        'channel_id' => $channel['channel_id'],
                        'plugin_code' => $plugin->getCode(),
                        'trace_no' => $requestId,
                        'reason_code' =>
                            $error instanceof BusinessException
                                ? 'business_rejected'
                                : 'task_fault',
                    ],
                    'warning',
                );
                $this->logger->exception($error);
            }
        }
        return $matched;
    }

    /**
     * 把插件标准数组转换为核心内部证据，并核对服务端授权；插件不构造 ReceiptEvent。
     *
     * @internal 仅供付款派发提交已通过 PaymentPluginPayResultValidator 的成功结果。
     * @param array<string,mixed> $result 标准字段和内部 payload_text 已验证，金额、归属及交易状态仍在此核对。
     * @return array{flow_id:?string,pay_no:?string,matched:bool,reason:string}
     */
    public function acceptPluginResult(
        array $result,
        ChannelNotifyContext $context,
        string $requestId,
    ): array {
        return $this->confirmResult(
            $result,
            $context,
            $requestId,
            $this->notification($context, $requestId, $result),
        );
    }

    /**
     * 消费已经验证的结果；批次先全部验证后逐笔确认，避免再次计算相同流水摘要。
     * 归属、金额、事务和幂等处理与单条通知共用，不减少交易核对条件。
     * @return array{flow_id:?string,pay_no:?string,matched:bool,reason:string}
     */
    private function confirmResult(
        array $result,
        ChannelNotifyContext $context,
        string $requestId,
        array $notification,
    ): array {
        try {
            $pay =
                $this->orders->find($result['pay_no']) ??
                throw new BusinessException('通知支付单不存在', ApiResponseConstant::NOT_FOUND);
            if ($context->payNo !== null && $pay['pay_no'] !== $context->payNo) {
                throw new BusinessException(
                    '通知与入口支付单不一致',
                    ApiResponseConstant::FORBIDDEN,
                );
            }
            if (
                $pay['channel_id'] === $context->channelId &&
                $pay['plugin_code'] === $context->pluginCode
            ) {
                $notification['pay_no'] = $pay['pay_no'];
            }
            if ($result['event_key'] === null) {
                return $this->lifecycle->confirmPayment(
                    $pay,
                    $result,
                    $context,
                    $requestId,
                    $notification,
                );
            }
            $channel = $this->channels->find($pay['channel_id']);
            $revision = $pay->channelRevision;
            // 首次自动确认仍须命中识别金额；原成功交易的重报/补证据只核对已确认实收。
            // 这里是入口校验，最终关联及幂等结论仍由生命周期在锁内复核。
            $confirmed = (int) $pay['status'] === TradeConstant::ORDER_STATUS_SUCCESS;
            $expectedAmount = $confirmed ? $pay->receivedAmount() : $pay->receiptAmount();
            $sameEvent =
                ($pay['ext_json']['personal_receipt']['event_key'] ?? null) ===
                $result['event_key'];
            if (
                $channel === null ||
                $revision === null ||
                $revision['api_config_id'] !== $context->configurationId ||
                $pay['channel_id'] !== $context->channelId ||
                $pay['receipt_scope_key'] !== $context->scopeKey ||
                $context->payType !== $result['pay_type'] ||
                $pay['plugin_code'] !== $context->pluginCode ||
                $result['paid_amount'] !== $pay->originalAmount() ||
                $result['received_amount'] !== $expectedAmount ||
                ($confirmed &&
                    ($pay['channel_trade_no'] !== $result['chan_trade_no'] ||
                        ($result['chan_trade_no'] === null && !$sameEvent)))
            ) {
                throw new BusinessException(
                    '插件通知与订单金额或归属不一致',
                    ApiResponseConstant::FORBIDDEN,
                );
            }
            $event = new ReceiptEvent(
                $pay['receipt_scope_key'],
                $context->configurationVersion,
                $result['pay_type'],
                $result['event_key'],
                $result['received_amount'],
                $result['paid_at'],
                $result['chan_trade_no'],
                $result['chan_order_no'],
                $context->pluginCode,
                $context->pluginVersion,
                $context->configurationId,
                $pay['pay_no'],
            );
            $accepted = $this->lifecycle->confirmReceipt(
                $pay,
                $event,
                $context->receivedAt,
                $requestId,
                $notification,
            );
            if (!$accepted['matched']) {
                $this->recordDiagnostic(
                    $notification,
                    Result::CALLBACK_REJECTED,
                    $accepted['reason'],
                );
                // 确认已返回，日志不进入交易事务；只留受控原因和关联号，不保存未匹配流水。
                // HTTP、采集及 pay() 成功结果共用此处，不能在只累计 matched 时丢掉拒绝原因。
                $this->logger->record(
                    'receipt.confirmation_rejected',
                    [
                        'pay_no' => $pay['pay_no'],
                        'channel_id' => $context->channelId,
                        'plugin_code' => $context->pluginCode,
                        'trace_no' => $requestId,
                        'reason_code' => $accepted['reason'],
                    ],
                    'warning',
                );
            }
            return $accepted;
        } catch (DbException | PDOException $error) {
            throw $error;
        } catch (Throwable $error) {
            $this->recordFailure($notification, $error);
            throw $error;
        }
    }

    /** 按原支付单快照装配通知；账号换 KEY、停用或通道换插件不改变旧单验签身份。 */
    private function orderContext(PayOrder $pay): ChannelNotifyContext
    {
        $snapshot = $this->revisions->forOrder($pay);
        $channel = $snapshot['channel'];
        $payType = $pay['ext_json']['payment_context']['pay_type'] ?? null;
        if (!is_string($payType) || $payType !== $this->catalog->typeCode($pay['pay_type_id'])) {
            throw new RuntimeException('原支付单的通知配置快照不一致');
        }
        return new ChannelNotifyContext(
            $pay['channel_id'],
            $channel['api_config_id'],
            $snapshot['config_version'],
            $pay['plugin_code'],
            $snapshot['plugin_version'],
            $pay['receipt_scope_key'],
            $payType,
            $snapshot['configuration'],
            $this->clock->now(),
            $pay['pay_no'],
        );
    }

    /**
     * 配置 → 通道 → 插件 → 范围的短事务生成授权快照；停用新单通道不改已有收款来源事实。
     * 配置开关限制新业务单，不阻断已有测试/业务单的到账；当前配置归属、版本及插件验签仍须通过。
     */
    private function channelContext(
        string $channelId,
        string $configurationId,
    ): ChannelNotifyContext {
        return $this->transaction(function () use (
            $channelId,
            $configurationId,
        ): ChannelNotifyContext {
            $configuration = $this->configurations->lock($configurationId);
            $channel = $this->channels->lock($channelId);
            if (
                $channel['api_config_id'] !== $configurationId ||
                $channel['plugin_code'] !== $configuration['plugin_code']
            ) {
                throw new BusinessException('来源配置不可用', ApiResponseConstant::FORBIDDEN);
            }
            $revision =
                $this->configurations->revision(
                    $configurationId,
                    (int) $configuration['config_version'],
                    true,
                ) ?? throw new RuntimeException('来源配置版本缺失');
            $definition = $this->plugins->definition($configuration['plugin_code']);
            if ($revision['config_json'] !== $configuration['config_json']) {
                throw new RuntimeException('账号配置正文不一致');
            }
            $authorized = $this->scopeAccess->current($configuration, true);
            $scope = $authorized[$channel['receipt_scope_key']] ?? null;
            if ($scope === null) {
                throw new BusinessException(
                    '该来源没有当前收款范围授权',
                    ApiResponseConstant::FORBIDDEN,
                );
            }
            $payType = $this->catalog->typeCode($scope['pay_type_id']);
            if (!in_array($payType, $definition->payTypes, true)) {
                throw new BusinessException(
                    '该来源没有当前收款范围授权',
                    ApiResponseConstant::FORBIDDEN,
                );
            }
            return new ChannelNotifyContext(
                $channelId,
                $configurationId,
                (int) $configuration['config_version'],
                $definition->code,
                $definition->version,
                $channel['receipt_scope_key'],
                $payType,
                $configuration['config_json'],
                $this->clock->now(),
            );
        });
    }

    /** 复用结果校验时编码的匹配项原文；不重建报文，也不读取整个 HTTP/采集批次代替它。 */
    private function notification(
        ChannelNotifyContext $context,
        string $requestId,
        array $result,
    ): array {
        $text = $result['payload_text'];
        return [
            'channel_id' => $context->channelId,
            'plugin_code' => $context->pluginCode,
            'pay_no' => $context->payNo,
            'request_id' => $requestId,
            'received_at' => $context->receivedAt->format('Y-m-d H:i:s'),
            'payload_digest' => hash('sha256', $text),
            'payload_text' => $text,
        ];
    }

    private function recordFailure(array $notification, Throwable $error): void
    {
        $business = $error instanceof BusinessException;
        $this->recordDiagnostic(
            $notification,
            $business ? Result::CALLBACK_REJECTED : Result::CALLBACK_FAULT,
            $business ? Result::CHANNEL_REJECTED : Result::CHANNEL_FAULT,
            $business ? $error->getMessage() : '处理异常，请通过请求编号查询技术日志',
        );
    }

    /** 失败事务已回滚后写一次元数据；未匹配的金额、时间和正文由仓储统一拒绝保存。 */
    private function recordDiagnostic(
        array $notification,
        int $status,
        string $reason,
        ?string $message = null,
    ): void {
        $this->transaction(
            fn() => $this->callbackLogs->record(
                [
                    'result_status' => $status,
                    'result_code' => $reason,
                    'message' => $message,
                ] + $notification,
            ),
        );
    }

    private function failureReply(?PaymentInterface $plugin, Throwable $error): string|Response
    {
        if ($error instanceof BusinessException) {
            $this->logger->exception($error);
            if ($plugin !== null && !($error instanceof UnsupportedPaymentOperationException)) {
                return $plugin->notifyFail($error->getMessage());
            }
        }
        throw $error;
    }
}
