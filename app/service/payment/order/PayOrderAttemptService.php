<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\routing\PaymentRouteService;

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
        private readonly \app\common\util\BusinessNumber $numbers,
        private readonly \think\Config $config,
    ) {
    }

    /**
     * 首次建立先核对配置与选路策略，再锁内部匹配范围并插入支付单；识别准备在创建提交后由插件执行。
     * 已有支付单时先锁 PayOrder，不重新评估通道策略或分配金额，终态也不创建第二次尝试。
     *
     * @param BizOrder $biz 调用方已在同一连接上锁定的业务单；再次当前读防止模型过期。
     * @param \DateTimeImmutable $now 本次用例的统一时间，内部转换为 Asia/Shanghai。
     * @return PayOrder 内部模型，公开响应必须另行取白名单字段。
     * @throws BusinessException 方式冲突、订单不可继续或没有可用的收款方案。
     * @throws \RuntimeException 快照损坏或内部关联失效；不能退回活动配置掩盖故障。
     */
    public function prepare(BizOrder $biz, string $payType, \DateTimeImmutable $now): PayOrder
    {
        if (!\app\common\validation\PaymentTypeCode::valid($payType)) {
            throw new BusinessException('不支持该支付方式', ApiResponseConstant::VALIDATION_FAILED);
        }
        $biz = $this->bizOrders->lock($biz['biz_no']);
        $context = $biz['ext_json'] ?? [];
        $requestedType = $context['requested_pay_type'] ?? null;
        if ($requestedType !== null && $requestedType !== $payType) {
            throw new BusinessException('支付方式与原始订单不一致', ApiResponseConstant::CONFLICT);
        }
        if ($biz['active_pay_no'] !== null) {
            $pay = $this->payOrders->lock($biz['active_pay_no']);
            if ($pay['biz_no'] !== $biz['biz_no'] || (int) $biz['attempt_count'] !== 1) {
                throw new \RuntimeException('业务单的支付尝试关联失效');
            }
            if (($pay['ext_json']['payment_context']['pay_type'] ?? null) !== $payType) {
                throw new BusinessException('订单已选择其他支付方式', ApiResponseConstant::CONFLICT);
            }
            return $pay;
        }
        // 等锁/事务重试可能耗时，分配不能使用比当前服务器时间更早的有效窗口判断。
        $now = max($now, $this->clock->now())->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        if ((int) $biz['status'] !== TradeConstant::ORDER_STATUS_CREATED
            || (int) $biz['attempt_count'] !== 0 || $biz['expire_at'] <= $now->format('Y-m-d H:i:s')) {
            throw new BusinessException('订单已不能建立支付尝试', ApiResponseConstant::CONFLICT);
        }
        $type = $this->catalog->lockType($payType);
        if ($type === null || (int) $type['enabled'] !== 1) {
            throw new BusinessException('该支付方式暂不可用', ApiResponseConstant::SERVICE_UNAVAILABLE);
        }
        $selection = $this->routes->selectFor($biz['app_id'], (int) $biz['order_amount'], $payType, $now);
        $channel = $selection['channel'];
        // 新支付行尚不存在，先拿范围排他锁，避免插入外键产生共享锁后升级死锁。
        $scope = $this->scopes->lock($channel['receipt_scope_key']);
        $now = max($now, $this->clock->now())->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        if ($biz['expire_at'] <= $now->format('Y-m-d H:i:s')) {
            throw new BusinessException('订单付款窗口已结束', ApiResponseConstant::CONFLICT);
        }
        if ($scope['pay_type_id'] !== $type['id']) {
            throw new BusinessException('收款范围支付方式不一致', ApiResponseConstant::CONFLICT);
        }
        // 建单只继承业务单截止；插件 pay() 准备自身付款材料时可在原截止内缩短窗口。
        $expireAt = $biz['expire_at'];
        $payNo = $this->numbers->insert('P', $now, fn (string $payNo) => $this->payOrders->create([
            'created_at' => $now->format('Y-m-d H:i:s'), 'pay_no' => $payNo, 'biz_no' => $biz['biz_no'], 'trace_no' => $biz['trace_no'],
            'poll_group_id' => $selection['poll_group_id'], 'route_mode' => $selection['route_mode'],
            'channel_id' => $channel['id'], 'channel_revision_id' => $channel['current_revision_id'],
            'pay_type_id' => $type['id'], 'plugin_code' => $channel['plugin_code'],
            'pay_amount' => (int) $biz['order_amount'],
            'receipt_scope_key' => $scope['receipt_scope_key'], 'expire_at' => $expireAt,
            'ext_json' => json_encode([
                'payment_context' => ['version' => 1, 'pay_type' => $payType, 'device' => $context['device'] ?? null],
                'personal_receipt' => [
                    // 测试身份只取自运行环境，不接受易支付参数伪造；后续切换入口仍明确标注合成订单。
                    'test_only' => $this->config->get('mpay.environment') === 'testing',
                    'account_key' => $selection['account_key'],
                    'config_version' => $selection['config_version'], 'plugin_version' => $selection['plugin_version'],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]));
        // 订单先提交；识别金额及备注由插件 pay() 在自己的短事务内准备。
        $this->bizOrders->attachAttempt($biz['biz_no'], $payNo);
        return $this->payOrders->find($payNo) ?? throw new \RuntimeException('已创建的支付单不可读取');
    }

}
