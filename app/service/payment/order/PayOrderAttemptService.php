<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\constant\ApiResponseConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\common\util\BusinessNumber;
use app\common\util\Clock;
use app\common\validation\PaymentTypeCode;
use app\exception\BusinessException;
use app\model\payment\BizOrder;
use app\model\payment\PaymentChannel;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\config\PaymentChannelRevisionService;
use app\service\payment\routing\PaymentRouteService;
use RuntimeException;
use think\Config;

/**
 * 在建单事务内固定支付尝试；识别准备交给服务随后调用的插件，不在创建事务内执行插件。
 * PayOrderService 持有主体和业务单锁并负责提交；已有尝试始终复用原通道与金额。
 */
final class PayOrderAttemptService
{
    public function __construct(
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderRepository $payOrders,
        private readonly PaymentCatalogRepository $catalog,
        private readonly ReceiptScopeRepository $scopes,
        private readonly PaymentRouteService $routes,
        private readonly Clock $clock,
        private readonly BusinessNumber $numbers,
        private readonly Config $config,
        private readonly PaymentChannelRepository $channels,
        private readonly PaymentPluginConfRepository $configurations,
        private readonly PaymentChannelRevisionService $revisions,
    ) {}

    /**
     * 首次建立先核对配置与选路策略，再锁内部匹配范围并插入支付单；识别准备在创建提交后由插件执行。
     * 已有支付单时先锁 PayOrder，不重新评估通道策略或分配金额，终态也不创建第二次尝试。
     *
     * @param BizOrder $biz 调用方在同一事务内刚锁定的业务单；复用当前模型，不重新查询。
     * @return PayOrder 内部模型，公开响应必须另行取白名单字段。
     * @throws BusinessException 方式冲突、订单不可继续或没有可用的收款方案。
     * @throws RuntimeException 快照损坏或内部关联失效；不能退回活动配置掩盖故障。
     */
    public function prepare(BizOrder $biz, string $payType): PayOrder
    {
        if (!PaymentTypeCode::valid($payType)) {
            throw new BusinessException('不支持该支付方式', ApiResponseConstant::VALIDATION_FAILED);
        }
        $context = $biz['ext_json'] ?? [];
        $requestedType = $context['requested_pay_type'] ?? null;
        if ($requestedType !== null && $requestedType !== $payType) {
            throw new BusinessException('支付方式与原始订单不一致', ApiResponseConstant::CONFLICT);
        }
        if ($biz['active_pay_no'] !== null) {
            $pay = $this->payOrders->lock($biz['active_pay_no']);
            if ($pay['biz_no'] !== $biz['biz_no'] || (int) $biz['attempt_count'] !== 1) {
                throw new RuntimeException('业务单的支付尝试关联失效');
            }
            if (($pay['ext_json']['payment_context']['pay_type'] ?? null) !== $payType) {
                throw new BusinessException(
                    '订单已选择其他支付方式',
                    ApiResponseConstant::CONFLICT,
                );
            }
            return $pay;
        }
        // 等锁/事务重试可能耗时，取得锁后重新读取时间判断付款窗口。
        $now = $this->clock->now();
        if (
            (int) $biz['status'] !== TradeConstant::ORDER_STATUS_CREATED ||
            (int) $biz['attempt_count'] !== 0 ||
            $biz['expire_at'] <= $now->format('Y-m-d H:i:s')
        ) {
            throw new BusinessException('订单已不能建立支付尝试', ApiResponseConstant::CONFLICT);
        }
        $selection = $this->routes->selectFor($biz['app_id'], (int) $biz['order_amount'], $payType);
        return $this->persist($biz, $selection['pay_type_id'], $payType, $selection);
    }

    /**
     * 已认证的后台建单入口直接指定通道；订单本身与普通订单共用持久化和生命周期。
     * 调用方持有应用和业务单锁，此处按支付方式→配置→通道→范围锁序核对已保存快照。
     * 已有支付尝试直接复用，新建或重投均不追加配置版本；重投不改通道或推进轮询游标。
     *
     * @throws BusinessException 原单通道不一致、订单不能继续或通道配置发生变化。
     */
    public function prepareByChannel(BizOrder $biz, string $channelId): PayOrder
    {
        if ($biz['active_pay_no'] !== null) {
            $pay = $this->payOrders->lock($biz['active_pay_no']);
            if (
                $pay['biz_no'] !== $biz['biz_no'] ||
                $pay['channel_id'] !== $channelId ||
                (int) $biz['attempt_count'] !== 1
            ) {
                throw new BusinessException(
                    '原订单的支付通道不一致',
                    ApiResponseConstant::CONFLICT,
                );
            }
            return $pay;
        }
        if (
            (int) $biz['status'] !== TradeConstant::ORDER_STATUS_CREATED ||
            (int) $biz['attempt_count'] !== 0
        ) {
            throw new BusinessException('订单已不能建立支付尝试', ApiResponseConstant::CONFLICT);
        }
        $observed = $this->channels->find($channelId);
        if ($observed === null || $observed['deleted_at'] !== null) {
            throw new BusinessException('通道不存在', ApiResponseConstant::NOT_FOUND);
        }
        $payType = $observed['pay_types'][0] ?? '';
        if (($biz['ext_json']['requested_pay_type'] ?? null) !== $payType) {
            throw new BusinessException('通道支付方式已变化', ApiResponseConstant::CONFLICT);
        }
        $type =
            $this->catalog->lockType($payType) ??
            throw new BusinessException('通道支付方式不存在', ApiResponseConstant::CONFLICT);
        $configuration = $this->configurations->lock($observed['api_config_id']);
        $channel = $this->channels->lock($channelId);
        if (
            $channel['api_config_id'] !== $configuration['id'] ||
            $channel['pay_types'] !== [$payType] ||
            $channel['deleted_at'] !== null
        ) {
            throw new BusinessException(
                '通道配置已变化，请重新发起',
                ApiResponseConstant::CONFLICT,
            );
        }
        // 测试复用保存时的快照，不修改通道版本；插件执行仍核验实际参数。
        if ($channel['current_revision_id'] === null) {
            throw new BusinessException('通道尚未保存配置快照', ApiResponseConstant::CONFLICT);
        }
        $snapshot = $this->revisions->snapshot($channelId, $channel['current_revision_id'], true);
        $configurationRevision = $this->configurations->revision(
            $configuration['id'],
            (int) $configuration['config_version'],
            true,
        );
        if (
            $channel['plugin_code'] !== $configuration['plugin_code'] ||
            $snapshot['config_version'] !== (int) $configuration['config_version'] ||
            !$this->revisions->rulesMatch($channel, $snapshot['channel']) ||
            $snapshot['configuration'] !== $configuration['config_json'] ||
            $configurationRevision === null ||
            $configurationRevision['config_json'] !== $configuration['config_json']
        ) {
            throw new BusinessException('通道配置与快照不一致，请重新保存', ApiResponseConstant::CONFLICT);
        }
        return $this->persist($biz, $type['id'], $payType, [
            'channel' => $channel,
            'config_version' => $snapshot['config_version'],
            'plugin_version' => $snapshot['plugin_version'],
            'poll_group_id' => null,
            'route_mode' => RouteConstant::DIRECT,
        ]);
    }

    /**
     * 两种建单入口共用金额范围锁、快照与唯一尝试写入，插件准备始终在提交之后执行。
     *
     * @param array{
     *     channel:PaymentChannel,
     *     config_version:int,
     *     plugin_version:string,
     *     poll_group_id:?string,
     *     route_mode:int
     * } $selection 已锁定并冻结的通道选择结果，直接指定时轮询组为空。
     */
    private function persist(
        BizOrder $biz,
        string $typeId,
        string $payType,
        array $selection,
    ): PayOrder {
        $context = $biz['ext_json'] ?? [];
        $channel = $selection['channel'];
        // 新支付行尚不存在，先拿范围排他锁，避免插入外键产生共享锁后升级死锁。
        $scope = $this->scopes->lock($channel['receipt_scope_key']);
        // 范围锁可能再次等待；编号、创建时间及付款截止判断使用同一次新读数。
        $now = $this->clock->now();
        if ($biz['expire_at'] <= $now->format('Y-m-d H:i:s')) {
            throw new BusinessException('订单付款窗口已结束', ApiResponseConstant::CONFLICT);
        }
        if ($scope['pay_type_id'] !== $typeId || $scope['api_config_id'] !== $channel['api_config_id']) {
            throw new BusinessException('收款范围归属不一致', ApiResponseConstant::CONFLICT);
        }
        // 建单只继承业务单截止；插件 pay() 准备自身付款材料时可在原截止内缩短窗口。
        $expireAt = $biz['expire_at'];
        $payNo = $this->numbers->insert(
            'P',
            $now,
            fn(string $payNo) => $this->payOrders->create([
                'created_at' => $now->format('Y-m-d H:i:s'),
                'pay_no' => $payNo,
                'biz_no' => $biz['biz_no'],
                'trace_no' => $biz['trace_no'],
                'poll_group_id' => $selection['poll_group_id'],
                'route_mode' => $selection['route_mode'],
                'channel_id' => $channel['id'],
                'channel_revision_id' => $channel['current_revision_id'],
                'pay_type_id' => $typeId,
                'plugin_code' => $channel['plugin_code'],
                'pay_amount' => (int) $biz['order_amount'],
                'receipt_scope_key' => $scope['receipt_scope_key'],
                'expire_at' => $expireAt,
                'ext_json' => json_encode(
                    [
                        'payment_context' => [
                            'version' => 1,
                            'pay_type' => $payType,
                            'device' => $context['device'] ?? null,
                        ],
                        'personal_receipt' => [
                            // 测试身份只取自运行环境，不接受易支付参数伪造；后续切换入口仍明确标注合成订单。
                            'test_only' => $this->config->get('mpay.environment') === 'testing',
                            'config_version' => $selection['config_version'],
                            'plugin_version' => $selection['plugin_version'],
                        ],
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            ]),
        );
        // 订单先提交；识别金额及备注由插件 pay() 在自己的短事务内准备。
        $this->bizOrders->attachAttempt($biz['biz_no'], $payNo);
        return $this->payOrders->find($payNo) ??
            throw new RuntimeException('已创建的支付单不可读取');
    }
}
