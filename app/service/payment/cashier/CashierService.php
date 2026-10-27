<?php
declare(strict_types=1);

namespace app\service\payment\cashier;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\TradeConstant;
use app\common\util\Clock;
use app\common\util\Money;
use app\common\validation\PaymentTypeCode;
use app\exception\BusinessException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentAppRepository;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\config\PaymentCatalogService;
use app\service\payment\order\PayOrderService;
use app\service\payment\plugin\PayOrderChannelDispatchService;
use app\service\payment\plugin\PaymentPluginPayResultValidator;
use app\service\payment\routing\PaymentRouteService;
use app\service\system\access\RequestRateLimiter;
use DateTimeImmutable;
use RuntimeException;
use think\Config;

/**
 * 持有随机订单凭证的付款页用例及公开数据白名单，不确认收款、不在页面请求中执行后台扫描。
 * GET 读取使用一致性事务，避免并发成功提交时把旧业务状态与新支付状态拼成一次响应。
 *
 * @phpstan-type CashierState array{biz_no:string,pay_no:?string,status:int,status_label:string,expires_at:int,
 *     expired:bool,can_select:bool,can_pay:bool,can_prepare:bool,processing:bool,needs_initial_prepare:bool,
 *     revision:string,server_time:int}
 * @phpstan-type CashierView array{biz_no:string,pay_no:?string,status:int,status_label:string,expires_at:int,
 *     expired:bool,can_select:bool,can_pay:bool,can_prepare:bool,processing:bool,needs_initial_prepare:bool,
 *     revision:string,server_time:int,subject:string,amount:?string,pay_type:?string,presentation:?array,
 *     payment_methods:list<array{code:string,name:string}>,payment_brands:array,test_mode:bool}
 * @phpstan-type CashierCompletion array{biz_no:string,status:int,required:bool,confirmed:bool,
 *     notification_status:?string,action:string,next_url:?string}
 */
final class CashierService extends BaseService
{
    public function __construct(
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderRepository $payOrders,
        private readonly PaymentRouteService $routes,
        private readonly PaymentAppRepository $apps,
        private readonly PayOrderService $orders,
        private readonly PayOrderChannelDispatchService $dispatch,
        private readonly PaymentCatalogRepository $catalog,
        private readonly NotifyTaskRepository $notifications,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly PaymentCatalogService $management,
        private readonly RequestRateLimiter $limiter,
    ) {}

    /**
     * 首屏/材料变化才读取完整信息；付款金额唯一，通知确认走 completion，不在详情加载通知表。
     * @return CashierView 公开数据，不暴露配置、凭证摘要或完整 ext_json。
     */
    public function view(string $bizNo, string $accessToken, DateTimeImmutable $now): array
    {
        return $this->transaction(function () use ($bizNo, $accessToken, $now): array {
            [$biz, $pay] = $this->authorisedOrders($bizNo, $accessToken);
            $this->limiter->check('cashier_read', $bizNo);
            return $this->present($biz, $pay, $now);
        });
    }

    /**
     * 已授权订单的公开展示投影，调用方须在一致性事务内；不派发支付或再次检查请求额度。
     * 查询和写后响应共用此处组装，避免把已经执行的写入误报为入口拒绝。
     * @return CashierView
     */
    private function present(BizOrder $biz, ?PayOrder $pay, DateTimeImmutable $now): array
    {
        $context = $pay === null ? [] : $pay['ext_json']['payment_context'] ?? [];
        $material = $pay === null ? null : $pay['ext_json']['presentation'] ?? null;
        $state = $this->state($biz, $pay, $context['execution'] ?? null, is_array($material), $now);
        $type = $context['pay_type'] ?? null;
        if ($pay !== null && !PaymentTypeCode::valid($type)) {
            throw new RuntimeException('支付方式快照缺失');
        }
        $presentation = null;
        if ($state['can_pay']) {
            // 直接读取插件公开快照；公共状态已有 expires_at/server_time，不为页面重算材料。
            $presentation = PaymentPluginPayResultValidator::presentation($material);
            if ($presentation['pay_type'] !== $type) {
                throw new RuntimeException('支付展示与原单方式不一致');
            }
        }
        $amount =
            $state['status'] === TradeConstant::ORDER_STATUS_SUCCESS
                ? $pay?->receivedAmount()
                : ($state['can_pay']
                    ? $pay->receiptAmount()
                    : null);
        return $state + [
            'subject' => $biz['subject'],
            'amount' => $amount === null ? null : Money::format($amount),
            'pay_type' => $type,
            'presentation' => $presentation,
            'payment_methods' => $state['can_select']
                ? $this->paymentMethods($biz['app_id'], (int) $biz['order_amount'])
                : [],
            'payment_brands' => $this->management->brands(),
            'test_mode' =>
                ($pay['ext_json']['personal_receipt']['test_only'] ?? false) === true ||
                $this->config->get('mpay.environment') === 'testing',
        ];
    }

    /** @return CashierState 最多两次定点投影查询；不查路由、品牌、流水或通知。 */
    public function status(string $bizNo, string $accessToken, DateTimeImmutable $now): array
    {
        return $this->transaction(function () use ($bizNo, $accessToken, $now): array {
            $biz = $this->bizOrders->cashierState($bizNo);
            $this->orders->assertAccess($biz, $accessToken);
            $this->limiter->check('cashier_read', $bizNo);
            $pay =
                $biz['active_pay_no'] === null
                    ? null
                    : $this->payOrders->cashierState($biz['active_pay_no']);
            $this->assertLink($biz, $pay);
            $phase = $pay['cashier_phase'] ?? null;
            $execution =
                $phase === null
                    ? null
                    : [
                        'phase' => $phase,
                        'lease_until' => $pay['cashier_lease_until'],
                    ];
            return $this->state(
                $biz,
                $pay,
                $execution,
                (int) ($pay['cashier_has_presentation'] ?? 0) === 1,
                $now,
            );
        });
    }

    /**
     * 支付成功后单独读取通知摘要；访问校验每次保留，不以客户端状态或通知号作为权限依据。
     * 首次 ACK 是不可撤销的接收事实，后续补发失败不阻止回跳。缺失应有任务属于故障。
     * @return CashierCompletion
     */
    public function completion(string $bizNo, string $accessToken): array
    {
        return $this->transaction(function () use ($bizNo, $accessToken): array {
            $biz = $this->bizOrders->cashierState($bizNo, true);
            $this->orders->assertAccess($biz, $accessToken);
            $this->limiter->check('cashier_read', $bizNo);
            return $this->completionResult(
                $biz,
                $accessToken,
                (int) $biz['cashier_notify_required'] === 1,
                (int) $biz['cashier_has_return'] === 1,
            );
        });
    }

    /**
     * 成功页在同一事务内授权、核对双单与金额并判断商户确认，每张订单只读一次，读取限流计一次。
     * 未满足本地成功条件时只返回导航判定；不组装付款材料，不查询品牌或可选路由。
     *
     * @return array{
     *     completion:CashierCompletion,
     *     payment:array{subject:string,amount:?string,pay_no:string,test_mode:bool}|null
     * }
     */
    public function successPage(string $bizNo, string $accessToken): array
    {
        return $this->transaction(function () use ($bizNo, $accessToken): array {
            [$biz, $pay] = $this->authorisedOrders($bizNo, $accessToken);
            $this->limiter->check('cashier_read', $bizNo);
            $completion = $this->completionResult(
                $biz,
                $accessToken,
                $biz['notify_url'] !== null,
                $biz['return_url'] !== null,
            );
            $payment = null;
            if ($completion['action'] === 'success') {
                $amount = $pay?->receivedAmount();
                $payment = [
                    'subject' => $biz['subject'],
                    'amount' => $amount === null ? null : Money::format($amount),
                    'pay_no' => $biz['active_pay_no'],
                    'test_mode' =>
                        ($pay['ext_json']['personal_receipt']['test_only'] ?? false) === true ||
                        $this->config->get('mpay.environment') === 'testing',
                ];
            }
            return ['completion' => $completion, 'payment' => $payment];
        });
    }

    /**
     * 已授权业务单的完成判定；调用方在同一事务读取订单并完成限流，此处不再授权或计数。
     * 两个布尔值表示订单是否保存通知/返回地址，供轻量投影与完整读取共用，不改变确认条件。
     *
     * @return CashierCompletion
     */
    private function completionResult(
        BizOrder $biz,
        string $accessToken,
        bool $requiresNotification,
        bool $hasReturn,
    ): array {
        $result = [
            'biz_no' => $biz['biz_no'],
            'status' => (int) $biz['status'],
            'required' => $requiresNotification,
            'confirmed' => false,
            'notification_status' => null,
            'action' => 'payment',
            'next_url' => null,
        ];
        if ((int) $biz['status'] !== TradeConstant::ORDER_STATUS_SUCCESS) {
            return $result;
        }
        if ($biz['active_pay_no'] === null) {
            throw new RuntimeException('成功订单缺少支付单');
        }
        if ($requiresNotification) {
            $result['notification_status'] = $this->notificationStatus(
                $biz['biz_no'],
                $biz['active_pay_no'],
            );
            $result['confirmed'] = $result['notification_status'] === 'success';
            if (!$result['confirmed']) {
                $result['action'] = in_array(
                    $result['notification_status'],
                    ['held', 'exhausted'],
                    true,
                )
                    ? 'attention'
                    : 'wait';
                return $result;
            }
        } elseif ($hasReturn) {
            // 有返回地址的订单必须具备通知责任；无通知地址的订单只允许本地成功页。
            throw new RuntimeException('订单返回地址缺少通知责任');
        }
        $result['action'] = $hasReturn ? 'return' : 'success';
        $result['next_url'] = (string) url('cashier.' . $result['action'], [
            'biz_no' => $biz['biz_no'],
            'token' => $accessToken,
        ])
            ->suffix(false)
            ->domain(false)
            ->root('');
        return $result;
    }

    /**
     * 已授权成功订单的商户应答状态；调用方须在一致性事务中读取订单，不在此重复授权或计数。
     * 完成页及签名回跳共用首次 ACK 规则，人工补发失败不能撤销已确认事实。
     */
    public function notificationStatus(string $bizNo, string $payNo): string
    {
        $this->requireTransaction();
        $task = $this->notifications->cashierConfirmation(
            NotifyConstant::EVENT_PAY_SUCCESS,
            $payNo,
        );
        if ($task === null || $task['biz_no'] !== $bizNo || $task['pay_no'] !== $payNo) {
            throw new RuntimeException('成功订单缺少原商户通知');
        }
        if ($task['first_ack_at'] !== null) {
            return 'success';
        }
        return match ((int) $task['status']) {
            NotifyConstant::STATUS_PENDING => 'pending',
            NotifyConstant::STATUS_SENDING => 'sending',
            NotifyConstant::STATUS_HELD => 'held',
            NotifyConstant::STATUS_EXHAUSTED => 'exhausted',
            default => throw new RuntimeException('商户通知状态与确认事实不一致'),
        };
    }

    /**
     * 完整详情和轻量轮询共用状态映射；revision 只反映展示相关事实，不依赖更新时间精度或新数据库字段。
     * @param array<string,mixed>|null $execution
     * @return array<string,mixed>
     */
    private function state(
        BizOrder $biz,
        ?PayOrder $pay,
        ?array $execution,
        bool $hasMaterial,
        DateTimeImmutable $now,
    ): array {
        $status = (int) $biz['status'];
        $expires = new DateTimeImmutable($pay === null ? $biz['expire_at'] : $pay['expire_at']);
        $expired = $expires <= $now;
        $waiting =
            in_array(
                $status,
                [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING],
                true,
            ) && !$expired;
        $canPay = $pay !== null && $waiting && $hasMaterial;
        if (
            $canPay &&
            ($status !== TradeConstant::ORDER_STATUS_PAYING ||
                $pay['request_at'] === null ||
                ($execution['phase'] ?? null) !== 'presented')
        ) {
            throw new RuntimeException('付款材料缺少已执行的准备上下文');
        }
        $canPrepare = $pay !== null && $waiting && $execution === null;
        if ($pay !== null && $waiting && !$canPay && $execution !== null) {
            $canPrepare =
                in_array($execution['phase'] ?? null, ['claimed', 'uncertain'], true) &&
                is_string($execution['lease_until'] ?? null) &&
                $execution['lease_until'] <= $now->format('Y-m-d H:i:s');
        }
        $data = [
            'biz_no' => $biz['biz_no'],
            'pay_no' => $pay === null ? null : $pay['pay_no'],
            'status' => $status,
            'status_label' => TradeConstant::label($status),
            'expires_at' => $expires->getTimestamp(),
            'expired' => $expired,
            'can_select' =>
                $pay === null && $status === TradeConstant::ORDER_STATUS_CREATED && !$expired,
            'can_pay' => $canPay,
            'can_prepare' => $canPrepare,
            'processing' => $pay !== null && $waiting && !$canPay,
            'needs_initial_prepare' => $canPrepare && $execution === null,
        ];
        $data['revision'] = hash(
            'sha256',
            json_encode([$data, $execution['phase'] ?? null], JSON_THROW_ON_ERROR),
        );
        return $data + ['server_time' => $now->getTimestamp()];
    }

    /** 两张订单必须指向同一尝试并拥有同一持久状态；不允许缺失支付单被误当成尚未选择。 */
    private function assertLink(BizOrder $biz, ?PayOrder $pay): void
    {
        if (
            $biz['active_pay_no'] !== null &&
            ($pay === null ||
                $pay['biz_no'] !== $biz['biz_no'] ||
                (int) $pay['status'] !== (int) $biz['status'])
        ) {
            throw new RuntimeException('收银台业务与支付状态不一致');
        }
    }

    /**
     * POST 用例：验证访问凭证后选择一次方式并准备材料；HTTP 边界另行检查同源及 CSRF。
     * 相同选择复用原尝试，不能把新的客户端金额、备注或通道号传入核心。
     *
     * @return CashierView 与 view 相同的公开白名单结构。
     */
    public function selectType(
        string $bizNo,
        string $accessToken,
        string $payType,
        string $requestId,
    ): array {
        // 在创建支付尝试前核验凭证并计数；选路服务仍在自己的事务里复核原单访问权限。
        $this->orders->assertAccess($this->bizOrders->find($bizNo), $accessToken);
        $this->limiter->check('cashier_write', $bizNo);
        $pay = $this->orders->selectPayType($bizNo, $accessToken, $payType);
        return $this->dispatchAndView($bizNo, $accessToken, $pay['pay_no'], $requestId);
    }

    /**
     * POST 用例：首次准备或在原执行期限到达后恢复材料；派发与生命周期核验原单、期限及执行 token。
     * 复用原识别事实和付款窗口，不依赖已退役的重放能力标志；轮询和后台任务不代为发起。
     *
     * @return CashierView 与 view 相同的公开白名单结构。
     */
    public function prepare(string $bizNo, string $accessToken, string $requestId): array
    {
        [, $pay] = $this->transaction(fn(): array => $this->authorisedOrders($bizNo, $accessToken));
        $this->limiter->check('cashier_write', $bizNo);
        if ($pay === null) {
            throw new BusinessException('请先选择支付方式', ApiResponseConstant::CONFLICT);
        }
        return $this->dispatchAndView($bizNo, $accessToken, $pay['pay_no'], $requestId);
    }

    /**
     * 写请求已完成授权和额度检查；事务外派发后，在独立一致性事务中读取当前展示。
     * 不复用受读额度限制的公开查询入口，不因写后回读超限而鼓励重发已执行的支付动作。
     * @return CashierView
     */
    private function dispatchAndView(
        string $bizNo,
        string $accessToken,
        string $payNo,
        string $requestId,
    ): array {
        $this->dispatch->dispatch($payNo, $this->clock->now(), $requestId);
        return $this->transaction(function () use ($bizNo, $accessToken): array {
            [$biz, $pay] = $this->authorisedOrders($bizNo, $accessToken);
            return $this->present($biz, $pay, $this->clock->now());
        });
    }

    /**
     * 调用者已开启只读一致性事务；返回内部模型只供本类白名单映射或确定固定支付号。
     *
     * @return array{BizOrder,PayOrder|null}
     */
    private function authorisedOrders(string $bizNo, string $accessToken): array
    {
        $biz = $this->bizOrders->find($bizNo);
        $this->orders->assertAccess($biz, $accessToken);
        $pay =
            $biz['active_pay_no'] === null ? null : $this->payOrders->find($biz['active_pay_no']);
        $this->assertLink($biz, $pay);
        // 完整读取额外核对金额；轻量状态查询无需加载金额快照。
        if ($pay !== null && $pay->originalAmount() !== (int) $biz['order_amount']) {
            throw new RuntimeException('收银台业务与支付状态不一致');
        }
        return [$biz, $pay];
    }

    /**
     * 复用正式选路的金额、开关、时段和容量检查；仅预览，不创建支付尝试或推进游标。
     * 只隐藏明确无可用通道的 503，配置损坏等程序异常仍交统一处理器报告。
     * @return list<array{code:string,name:string}>
     */
    private function paymentMethods(string $appId, int $amount): array
    {
        $app = $this->apps->lock($appId);
        if ((int) $app['status'] !== 1) {
            return [];
        }
        $methods = [];
        $types = $this->catalog->enabledTypes();
        // 多方式预览在同一事务内，锁序不跟随可编辑的显示顺序。
        usort(
            $types,
            static fn($a, $b): int => strlen($a['id']) <=> strlen($b['id']) ?:
            strcmp($a['id'], $b['id']),
        );
        // 先锁齐各方式，再接触可能跨方式复用的插件配置，避免预览反向等待通道编辑。
        foreach ($types as $type) {
            $this->catalog->find($type['id'], true);
        }
        foreach ($types as $type) {
            try {
                $this->routes->selectFor($appId, $amount, $type['code'], false);
            } catch (BusinessException $exception) {
                if ($exception->getCode() === ApiResponseConstant::SERVICE_UNAVAILABLE) {
                    continue;
                }
                throw $exception;
            }
            $methods[] = ['code' => $type['code'], 'name' => $type['name']];
        }
        return $methods;
    }
}
