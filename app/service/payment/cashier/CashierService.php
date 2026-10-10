<?php
declare(strict_types=1);

namespace app\service\payment\cashier;

use app\common\constant\ApiResponseConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\TradeConstant;
use app\common\util\Clock;
use app\common\util\Money;
use app\common\base\BaseService;
use app\exception\BusinessException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\plugin\PayOrderChannelDispatchService;
use app\service\payment\order\PayOrderService;
use think\Config;

/**
 * 持有随机订单凭证的付款页用例及公开数据白名单，不确认收款、不在页面请求中执行后台扫描。
 * GET 读取使用一致性事务，避免并发成功提交时把旧业务状态与新支付状态拼成一次响应。
 *
 * @phpstan-import-type CashierPresentation from PaymentPresentation
 * @phpstan-type CashierView array{biz_no:string,pay_no:?string,subject:string,status:int,status_label:string,
 *     order_money:string,receipt_money:?string,received_money:?string,offset_money:?string,
 *     pay_type:?string,match_mode:?string,remark:?string,server_time:int,expires_at:int,expired:bool,
 *     can_select:bool,can_pay:bool,can_prepare:bool,processing:bool,
 *     payment_methods:list<array{code:string,name:string}>,
 *     presentation:CashierPresentation|null,
 *     notification_status:?string,return_allowed:bool,test_mode:bool}
 */
final class CashierService extends BaseService
{
    public function __construct(
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderRepository $payOrders,
        private readonly \app\service\payment\routing\PaymentRouteService $routes,
        private readonly \app\repository\payment\config\PaymentAppRepository $apps,
        private readonly PayOrderService $orders,
        private readonly PayOrderChannelDispatchService $dispatch,
        private readonly PaymentCatalogRepository $catalog,
        private readonly NotifyTaskRepository $notifications,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly \app\service\payment\config\PaymentCatalogService $management,
    ) {
    }

    /**
     * 只返回公开付款字段；金额文本由整数分格式化，时间戳均为 Unix 秒。
     * expired 是服务端判断的付款窗口信息，不伪造一个尚未落库的 TIMEOUT 状态。
     *
     * @return CashierView
     */
    public function view(string $bizNo, string $accessToken, \DateTimeImmutable $now): array
    {
        return $this->transaction(function () use ($bizNo, $accessToken, $now): array {
            [$biz, $pay] = $this->authorisedOrders($bizNo, $accessToken);
            $status = (int) $biz['status'];
            $expires = new \DateTimeImmutable($pay === null ? $biz['expire_at'] : $pay['expire_at'], new \DateTimeZone('Asia/Shanghai'));
            $expired = $expires <= $now;
            $waiting = in_array($status, [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true);
            $receipt = $pay === null ? [] : ($pay['ext_json']['personal_receipt'] ?? []);
            $payment = $pay === null ? [] : ($pay['ext_json']['payment_context'] ?? []);
            $type = $payment['pay_type'] ?? null;
            $mode = $receipt['match_mode'] ?? null;
            $remark = $receipt['remark'] ?? null;
            // 建单提交与插件准备之间、以及平台结果未知时可以尚无识别材料；仍应展示处理中。
            if ($pay !== null && (!\app\common\validation\PaymentTypeCode::valid($type)
                || ($mode !== null && !in_array($mode, ['amount', 'remark'], true))
                || ($mode === 'remark' && (!is_string($remark) || $remark === '')))) {
                throw new \RuntimeException('收银台支付识别快照缺失');
            }
            $execution = $payment['execution'] ?? null;
            $presentation = null;
            $material = $pay === null ? null : ($pay['ext_json']['presentation'] ?? null);
            if ($waiting && !$expired && is_array($material)) {
                if (!in_array($mode, ['amount', 'remark'], true) || $pay === null || $status !== TradeConstant::ORDER_STATUS_PAYING || $pay['request_at'] === null
                    || !is_array($execution) || ($execution['phase'] ?? null) !== 'presented') {
                    throw new \RuntimeException('付款材料缺少已执行的准备上下文');
                }
                // 再经标准公开合同读取，不把 ext_json 的任意额外键交给模板或浏览器。
                $presentation = (new PaymentPresentation($material['qrcode_content'] ?? null, $material['qrcode_image_url'] ?? null))
                    ->forCashier($type, $mode, (int) $biz['order_amount'], (int) $pay->receiptAmount(), $remark, $expires, $now);
            }
            $canPrepare = $pay !== null && $waiting && !$expired && $execution === null;
            if (is_array($execution) && $waiting && !$expired && $presentation === null) {
                $canPrepare = ($execution['replay_safe'] ?? null) === true
                    && in_array($execution['phase'] ?? null, ['claimed', 'uncertain'], true)
                    && is_string($execution['lease_until'] ?? null)
                    && $execution['lease_until'] <= $now->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
            }
            $notification = null;
            if ($status === TradeConstant::ORDER_STATUS_SUCCESS) {
                if ($pay === null) {
                    throw new \RuntimeException('成功业务单缺少支付单');
                }
                $task = $this->notifications->findByEvent(NotifyConstant::EVENT_PAY_SUCCESS, $pay['pay_no']);
                if ($task === null || $task['biz_no'] !== $bizNo) {
                    throw new \RuntimeException('成功订单缺少原通知责任');
                }
                $notification = $task['first_ack_at'] !== null ? 'success' : match ((int) $task['status']) {
                    NotifyConstant::STATUS_PENDING => 'pending',
                    NotifyConstant::STATUS_SENDING => 'sending',
                    NotifyConstant::STATUS_SUCCESS => 'success',
                    NotifyConstant::STATUS_EXHAUSTED => 'exhausted',
                    NotifyConstant::STATUS_HELD => 'held',
                    default => throw new \RuntimeException('未知通知状态'),
                };
            }
            $canSelect = $pay === null && $status === TradeConstant::ORDER_STATUS_CREATED && !$expired;
            return [
                'biz_no' => $biz['biz_no'], 'pay_no' => $pay === null ? null : $pay['pay_no'], 'subject' => $biz['subject'],
                'status' => $status, 'status_label' => TradeConstant::label($status),
                'order_money' => Money::format((int) $biz['order_amount']),
                'receipt_money' => $pay === null ? null : Money::format((int) $pay->receiptAmount()),
                'received_money' => $pay === null || $pay->receivedAmount() === null ? null : Money::format((int) $pay->receivedAmount()),
                'offset_money' => $pay === null ? null : Money::format((int) $pay->receiptAmount() - $pay->originalAmount()),
                'payment_brands' => $this->management->brands(), 'pay_type' => $type, 'match_mode' => $mode, 'remark' => $mode === 'remark' ? $remark : null,
                'server_time' => $now->getTimestamp(), 'expires_at' => $expires->getTimestamp(), 'expired' => $expired,
                'can_select' => $canSelect, 'can_pay' => $presentation !== null,
                'can_prepare' => $canPrepare, 'processing' => $pay !== null && $waiting && !$expired && $presentation === null,
                'payment_methods' => $canSelect ? $this->paymentMethods($biz['app_id'], (int) $biz['order_amount'], $now) : [], 'presentation' => $presentation,
                'notification_status' => $notification,
                // 只表示可发起受保护的回跳请求；签名地址由易支付适配器在再次核验成功后构造。
                'return_allowed' => $status === TradeConstant::ORDER_STATUS_SUCCESS && $biz['return_url'] !== null,
                // 模拟身份随订单冻结，即使以后读取环境变化，也不能把模拟确认展示成真实到账。
                'test_mode' => ($receipt['test_only'] ?? false) === true || $this->config->get('mpay.environment') === 'testing',
            ];
        });
    }

    /**
     * POST 用例：验证访问凭证后选择一次方式并准备材料；HTTP 边界另行检查同源及 CSRF。
     * 相同选择复用原尝试，不能把新的客户端金额、备注或通道号传入核心。
     *
     * @return CashierView 与 view 完全相同的公开白名单结构。
     */
    public function selectType(string $bizNo, string $accessToken, string $payType, string $requestId): array
    {
        $pay = $this->orders->selectPayType($bizNo, $accessToken, $payType, $this->clock->now());
        $this->dispatch->dispatch($pay['pay_no'], $this->clock->now(), $requestId);
        return $this->view($bizNo, $accessToken, $this->clock->now());
    }

    /**
     * POST 用例：恢复已有支付执行的材料，是否可重放仍由派发/生命周期按原能力与 token 决定。
     *
     * @return CashierView 与 view 完全相同的公开白名单结构。
     */
    public function prepare(string $bizNo, string $accessToken, string $requestId): array
    {
        [, $pay] = $this->transaction(fn (): array => $this->authorisedOrders($bizNo, $accessToken));
        if ($pay === null) {
            throw new BusinessException('请先选择支付方式', ApiResponseConstant::CONFLICT);
        }
        $this->dispatch->dispatch($pay['pay_no'], $this->clock->now(), $requestId);
        return $this->view($bizNo, $accessToken, $this->clock->now());
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
        $pay = $biz['active_pay_no'] === null ? null : $this->payOrders->find($biz['active_pay_no']);
        if ($biz['active_pay_no'] !== null && ($pay === null || $pay['biz_no'] !== $bizNo
            || (int) $pay['status'] !== (int) $biz['status'] || $pay->originalAmount() !== (int) $biz['order_amount'])) {
            throw new \RuntimeException('收银台业务与支付状态不一致');
        }
        return [$biz, $pay];
    }

    /**
     * 复用正式选路的金额、开关、时段和容量检查；仅预览，不创建支付尝试或推进游标。
     * 只隐藏明确无可用通道的 503，配置损坏等程序异常仍交统一处理器报告。
     * @return list<array{code:string,name:string}>
     */
    private function paymentMethods(string $appId, int $amount, \DateTimeImmutable $now): array
    {
        $app = $this->apps->lock($appId);
        if ((int) $app['status'] !== 1) { return []; }
        $methods = [];
        $types = $this->catalog->enabledTypes();
        // 多方式预览在同一事务内，锁序不跟随可编辑的显示顺序。
        usort($types, static fn ($a, $b): int => strlen($a['id']) <=> strlen($b['id']) ?: strcmp($a['id'], $b['id']));
        // 先锁齐各方式，再接触可能跨方式复用的插件配置，避免预览反向等待通道编辑。
        foreach ($types as $type) { $this->catalog->find($type['id'], true); }
        foreach ($types as $type) {
            try { $this->routes->selectFor($appId, $amount, $type['code'], $now, false); }
            catch (BusinessException $exception) {
                if ($exception->getCode() === ApiResponseConstant::SERVICE_UNAVAILABLE) { continue; }
                throw $exception;
            }
            $methods[] = ['code' => $type['code'], 'name' => $type['name']];
        }
        return $methods;
    }
}
