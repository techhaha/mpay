<?php
declare(strict_types=1);

namespace app\service\payment\epay;

use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\util\Clock;
use app\common\util\HttpTargetPolicy;
use app\common\util\SiteUrl;
use app\common\base\BaseService;
use app\exception\BusinessException;
use app\exception\PaymentDefinitiveException;
use app\model\payment\PaymentApp;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentAppRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\config\PaymentAppCredentialService;
use app\service\payment\plugin\PayOrderChannelDispatchService;
use app\service\payment\order\PayOrderService;

/**
 * 易支付 V1 的认证及协议适配，不与后台 code=200 JSON 混用，不接受浏览器回跳作为到账确认。
 * 原始参数先验签，再做业务规范化；下单、派发、只读查询各自保持已有的事务边界。
 */
final class EpayV1ProtocolService extends BaseService
{
    public function __construct(
        private readonly PaymentAppRepository $apps,
        private readonly PaymentAppCredentialService $credentials,
        private readonly EpayV1Signer $signer,
        private readonly EpayV1Validator $validator,
        private readonly PayOrderService $orders,
        private readonly \app\service\payment\order\RefundService $refunds,
        private readonly PayOrderChannelDispatchService $dispatch,
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderRepository $payOrders,
        private readonly EpayV1MessageService $messages,
        private readonly HttpTargetPolicy $targets,
        private readonly SiteUrl $site,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string|int,string> $raw 未 trim/截断/转换的原始表单标量。
     * @return array{trade_no:string,payurl:string,reused:bool} 内部跳转结果，Controller 再适配 submit/mapi。
     */
    public function create(#[\SensitiveParameter] array $raw, string $scene, ?string $clientIp, string $device, string $requestId): array
    {
        $identity = $this->authenticate($raw, true);
        $input = $this->validator->order($raw, $scene, $identity['app']['id'], $identity['version'], $clientIp, $device);
        // 校验本次访问来源可构造收银台链接；多个域名各自返回当前域名，不把站点地址写入订单。
        $this->site->origin();
        $created = $this->orders->create($input, $this->clock->now());
        if ($created->payNo !== null) {
            $pay = $this->dispatch->dispatch($created->payNo, $this->clock->now(), $requestId);
            if ((int) $pay['status'] === TradeConstant::ORDER_STATUS_FAILED) {
                throw new PaymentDefinitiveException();
            }
        }
        return ['trade_no' => $created->payNo ?? '',
            'payurl' => $this->site->path('/cashier/pay', ['biz_no' => $created->bizNo, 'token' => $created->accessToken]),
            'reused' => $created->reused];
    }

    /**
     * 查询使用应用当前 PID/KEY；停用后拒绝新 API 请求，原订单通知和收银台责任仍保留。
     *
     * @param array<string|int,string> $raw
     * @return array<string, int|string|array> 易支付自己的结构；金额为元字符串，绝不返回余额托管假数据。
     */
    public function api(#[\SensitiveParameter] array $raw): array
    {
        $identity = $this->authenticate($raw, false);
        $app = $identity['app'];
        $action = $raw['act'] ?? 'query';
        if ($action === 'settle') {
            return ['code' => 1, 'msg' => '本系统自收，无平台结算记录', 'data' => []];
        }
        if ($action === 'refund') {
            $selector = $this->validator->queryIdentity($raw);
            $input = $this->validator->refund($raw);
            if ($selector['trade_no'] !== null) {
                $payNo = $selector['trade_no'];
            } else {
                $biz = $this->bizOrders->findByAppOrder($app['id'], $selector['out_trade_no']);
                $payNo = $biz['active_pay_no'] ?? null;
                if ($payNo === null) { throw new BusinessException('支付订单不存在', ApiResponseConstant::NOT_FOUND); }
            }
            // 退款内部核对应用归属、成功事实和剩余额度；是否支持退款由原订单插件决定。
            $refund = $this->refunds->refund($app['id'], $payNo, $input['amount'], $input['request_no']);
            $status = (int) $refund['status'];
            return ['code' => $status === \app\common\constant\RefundConstant::SUCCESS ? 1 : 0,
                'msg' => $refund['result_code'] === \app\common\constant\PaymentResultCode::REFUND_UNSUPPORTED ? '此订单的插件不支持接口退款，请联系收款方处理'
                    : \app\common\constant\RefundConstant::label($status),
                'refund_no' => $refund['refund_no'], 'trade_no' => $payNo,
                'money' => \app\common\util\Money::format((int) $refund['refund_amount']), 'refund_status' => $status];
        }
        if (!in_array($action, ['order', 'orders', 'query'], true)) {
            throw new BusinessException('不支持的易支付操作', ApiResponseConstant::BAD_REQUEST);
        }
        return $this->transaction(function () use ($raw, $app, $action): array {
            if ($action === 'order') {
                $selector = $this->validator->queryIdentity($raw);
                if ($selector['trade_no'] !== null) {
                    $pay = $this->payOrders->find($selector['trade_no']);
                    $biz = $pay === null ? null : $this->bizOrders->find($pay['biz_no']);
                } else {
                    $biz = $this->bizOrders->findByAppOrder($app['id'], $selector['out_trade_no']);
                }
                if ($biz === null || $biz['app_id'] !== $app['id']) {
                    throw new BusinessException('订单不存在', ApiResponseConstant::NOT_FOUND);
                }
                return $this->messages->order($biz, $this->payFor($biz), $biz['pid']);
            }
            if ($action === 'orders') {
                $page = $this->validator->pagination($raw);
                $orders = $this->bizOrders->pageForApp($app['id'], $page['page'], $page['limit']);
                $payments = $this->payOrders->forBizOrders(array_map(static fn (BizOrder $biz): string => $biz['biz_no'], $orders));
                $items = [];
                foreach ($orders as $biz) {
                    $pay = $payments[$biz['biz_no']] ?? null;
                    if ($biz['active_pay_no'] !== ($pay === null ? null : $pay['pay_no'])) {
                        throw new \RuntimeException('分页查询的支付关联不一致');
                    }
                    $items[] = $this->messages->order($biz, $pay, $biz['pid']);
                }
                return ['code' => 1, 'msg' => '查询成功', 'data' => $items];
            }
            $today = $this->clock->now()->setTimezone(new \DateTimeZone('Asia/Shanghai'))->setTime(0, 0);
            return ['code' => 1, 'msg' => '本系统不托管资金，余额固定为 0.00', 'pid' => $app['pid'],
                'active' => (int) $app['status'], 'money' => '0.00',
                'orders' => $this->bizOrders->countForApp($app['id']),
                'order_today' => $this->bizOrders->countForApp($app['id'], $today->format('Y-m-d H:i:s'), $today->modify('+1 day')->format('Y-m-d H:i:s')),
                'order_lastday' => $this->bizOrders->countForApp($app['id'], $today->modify('-1 day')->format('Y-m-d H:i:s'), $today->format('Y-m-d H:i:s'))];
        });
    }

    /**
     * 只对持有访问凭证且本地已经成功的订单生成签名回跳；此调用不修改任何支付状态。
     * return_url 的 query 可能与 notify_url 不同，必须用原版本 KEY 独立合并签名。
     */
    public function returnUrl(string $bizNo, string $accessToken): string
    {
        return $this->transaction(function () use ($bizNo, $accessToken): string {
            $biz = $this->bizOrders->find($bizNo);
            $this->orders->assertAccess($biz, $accessToken);
            $pay = $this->payFor($biz);
            if ($pay === null || (int) $biz['status'] !== TradeConstant::ORDER_STATUS_SUCCESS) {
                throw new BusinessException('订单尚未确认支付成功', ApiResponseConstant::CONFLICT);
            }
            if ($biz['return_url'] === null) {
                throw new BusinessException('此订单未设置回跳地址，请留在结果页', ApiResponseConstant::CONFLICT);
            }
            // 已授权订单的冻结目标不随应用后续修改失效；仍检查地址格式与网络边界。
            $host = HttpTargetPolicy::uri($biz['return_url'])->getHost();
            $this->targets->forHosts([$host])->validate($biz['return_url']);
            $parameters = $this->messages->signForTarget($biz['return_url'],
                $this->messages->successParameters($biz, $pay, $biz['pid']),
                $this->credentials->secretForVersion($biz['app_id'], $biz['credential_version_id']));
            return $this->messages->targetUrl($biz['return_url'], $parameters);
        });
    }

    /**
     * 不把 KEY 放入返回结构或认证失败消息；新建单会在应用锁下再次核对 PID 和活动密钥版本。
     *
     * @param array<string|int,string> $raw
     * @return array{app:PaymentApp,version:string}
     */
    private function authenticate(#[\SensitiveParameter] array $raw, bool $signed): array
    {
        $pid = $this->validator->pid($raw);
        if (($signed && (($raw['sign_type'] ?? null) !== 'MD5' || !preg_match('/^[a-f0-9]{32}$/D', $raw['sign'] ?? '')))
            || (!$signed && (($raw['key'] ?? '') === '' || strlen($raw['key']) > 512))) {
            throw new BusinessException('PID、KEY 或签名无效', ApiResponseConstant::UNAUTHENTICATED);
        }
        return $this->transaction(function () use ($pid, $raw, $signed): array {
            $app = $this->apps->findByPid($pid);
            if ($app === null || (int) $app['status'] !== 1) {
                throw new BusinessException('PID、KEY 或签名无效', ApiResponseConstant::UNAUTHENTICATED);
            }
            $version = $this->credentials->active($app);
            $valid = $version !== null && ($signed
                ? $this->signer->verify($raw, $this->credentials->secretForVersion($app['id'], $version['id']))
                : hash_equals($version['key_fingerprint'], hash('sha256', $raw['key'])));
            if (!$valid) { throw new BusinessException('PID、KEY 或签名无效', ApiResponseConstant::UNAUTHENTICATED); }
            $matched = $version['id'];
            return ['app' => $app, 'version' => $matched];
        });
    }

    /** 已在只读事务中加载的业务单对应的唯一支付尝试；缺失关联不能当成普通未支付。 */
    private function payFor(BizOrder $biz): ?PayOrder
    {
        if ($biz['active_pay_no'] === null) {
            if ((int) $biz['attempt_count'] !== 0 || (int) $biz['status'] === TradeConstant::ORDER_STATUS_SUCCESS) {
                throw new \RuntimeException('业务单的支付关联缺失');
            }
            return null;
        }
        return $this->payOrders->find($biz['active_pay_no']) ?? throw new \RuntimeException('业务单引用的支付单缺失');
    }
}
