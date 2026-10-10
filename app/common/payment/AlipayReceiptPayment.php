<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\interface\ChannelNotifyInterface;
use app\common\interface\ReceiptMatcherInterface;
use app\common\sdk\smsforwarder\SmsForwarderVerifier;
use app\common\util\Clock;
use app\common\util\Money;
use app\exception\BusinessException;
use app\exception\ReceiptMessageException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\receipt\ReceiptMatchReservationRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use think\Request;
use think\Response;
use think\facade\Db;

/** 支付宝手机监听：本类集中配置、通知模板及字段转换，标准订单处理复用服务调用链。 */
final class AlipayReceiptPayment implements ChannelNotifyInterface, ReceiptMatcherInterface
{
    /** @var array<string,mixed> 随代码交付的元信息和配置字段列表，不含实际账号秘密。 */
    private array $paymentInfo = [
        'code' => 'alipay_receipt', 'name' => '支付宝手机收款监听', 'version' => '1.0.0', 'pay_types' => ['alipay'],
        'config_schema' => [
            ['type' => 'select', 'field' => 'receipt_match_mode', 'title' => '匹配方式',
                'options' => [['value' => 'amount', 'label' => '按金额识别'], ['value' => 'remark', 'label' => '按备注识别']],
                'validate' => [0 => 'require', 'in' => ['amount', 'remark']], 'value' => 'amount'],
            ['type' => 'number', 'field' => 'receipt_valid_seconds', 'title' => '付款有效期（秒）',
                'validate' => [0 => 'require', 'between' => [1, 86400]], 'value' => 300],
            ['type' => 'number', 'field' => 'amount_offset_max', 'title' => '金额偏移上限（分）',
                'validate' => [0 => 'require', 'between' => [0, 99]], 'value' => 99,
                'visible_when' => ['field' => 'receipt_match_mode', 'value' => 'amount'], 'help' => '用于区分同一收款范围的待付订单；0 表示不增加金额。'],
            ['type' => 'textarea', 'field' => 'receipt_qrcode_content', 'title' => '收款码内容', 'value' => '',
                'validate' => [0 => 'string', 'regex' => '/^[\s\S]{0,2331}$/D', 'requireWithout' => 'receipt_qrcode_image'],
                'help' => '填写二维码解码后的文本，或上传收款码图片。'],
            ['type' => 'upload', 'field' => 'receipt_qrcode_image', 'title' => '收款码图片', 'value' => '', 'help' => '支持 PNG、JPEG、WebP 图片，最大 2 MiB。',
                'validate' => [0 => 'string', 'regex' => '/^(?:[1-9][0-9]{0,19})?$/D', 'requireWithout' => 'receipt_qrcode_content']],
            ['type' => 'input', 'field' => 'sms_forwarder_secret', 'title' => '通知密钥', 'validate' => [0 => 'require', 1 => 'string', 'length' => [16, 1024]], 'secret' => true],
            ['type' => 'number', 'field' => 'sms_forwarder_time_tolerance', 'title' => '通知时间容差（秒）', 'validate' => [0 => 'require', 'between' => [30, 3600]], 'value' => 300],
        ],
    ];

    public function __construct(private readonly PayOrderRepository $orders, private readonly BizOrderRepository $businessOrders, private readonly ReceiptScopeRepository $scopes,
        private readonly ReceiptMatchReservationRepository $reservations, private readonly Clock $clock, private readonly SmsForwarderVerifier $verifier)
    {
    }

    /** @var array<string,mixed> 本次实例的原配置，不能序列化给页面或日志。 */
    private array $channelConfig = [];

    public function init(#[\SensitiveParameter] array $channelConfig): void
    {
        $this->channelConfig = $channelConfig;
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->channelConfig[$key] ?? $default;
    }

    public function getCode(): string { return $this->paymentInfo['code']; }
    public function getDescription(): string { return $this->paymentInfo['description'] ?? ''; }
    public function getName(): string { return $this->paymentInfo['name']; }
    public function getVersion(): string { return $this->paymentInfo['version']; }
    public function getEnabledPayTypes(): array { return $this->paymentInfo['pay_types']; }
    public function getConfigSchema(): array { return $this->paymentInfo['config_schema']; }

    /** 本插件仅生成本地识别材料，原订单下重复准备会复用已持久化的金额和备注。 */
    public function canRetryPay(): bool { return true; }

    /** 按当前通道准备识别值并生成静态码材料；事务提交后返回 pending，不推进交易成功。 */
    public function pay(array $order): array
    {
        $pay = $this->prepareReceipt($order['pay_no']);
        return ['status' => 'pending', 'pay_no' => $order['pay_no'], 'paid_amount' => null,
            'chan_order_no' => null, 'chan_trade_no' => null,
            'pay_type' => $order['pay_type_code'], 'pay_product' => 'receipt', 'pay_action' => 'qrcode', 'presentation' => [
            'pay_page' => 'page',
            'pay_params' => ['_page' => 'receiptQrcode', 'qrcode_content' => $this->getConfig('receipt_qrcode_content') ?: null,
                'qrcode_image_url' => $this->getConfig('receipt_qrcode_image_url'),
                'order_money' => Money::format($pay->originalAmount()), 'receipt_money' => Money::format($pay->receiptAmount()),
                'remark' => $pay['ext_json']['personal_receipt']['remark'] ?? null],
        ]];
    }

    /**
     * 先保存原金额，再把 pay_amount 改为识别金额；刷新/执行恢复复用同一份准备结果。
     * 插件由派发服务在事务外调用，本方法拥有自己的数据库事务，锁顺序与生命周期一致。
     */
    private function prepareReceipt(string $payNo): PayOrder
    {
        $observed = $this->orders->find($payNo) ?? throw new \RuntimeException('收款支付单不存在');
        return Db::transaction(function () use ($observed, $payNo): PayOrder {
            $biz = $this->businessOrders->lock($observed['biz_no']);
            $pay = $this->orders->lock($payNo);
            $this->scopes->lock($pay['receipt_scope_key']);
            if ($pay['channel_id'] !== $this->getConfig('channel_id') || $pay['biz_no'] !== $biz['biz_no']) {
                throw new \LogicException('插件与支付单通道不一致');
            }
            $ext = $pay['ext_json'] ?? [];
            if (isset($ext['personal_receipt']['original_amount'])) { return $pay; }
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            if (!in_array((int) $pay['status'], [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true)
                || $pay['expire_at'] <= $now) {
                throw new BusinessException('支付单不能继续准备收款', ApiResponseConstant::CONFLICT);
            }
            // 付款窗口属于本插件；不超过原业务截止，重放时复用已准备的窗口。
            $expiresAt = min($pay['expire_at'], $this->clock->now()->modify('+' . $this->getConfig('receipt_valid_seconds', 300) . ' seconds')->format('Y-m-d H:i:s'));
            $original = (int) $biz['order_amount'];
            Money::assertRange($original);
            if ($original < 1 || $original > (int) $this->getConfig('max_amount', Money::MAX_CENTS)) {
                throw new BusinessException('金额超出通道限额', ApiResponseConstant::VALIDATION_FAILED);
            }
            $mode = $this->getConfig('receipt_match_mode', 'amount');
            $remark = null;
            $this->reservations->releaseExpired($pay['receipt_scope_key'], $now);
            $amount = $original;
            if ($mode === 'remark') {
                for ($attempt = 0; $attempt < 100; $attempt++) {
                    $remark = (string) random_int(1000, 9999);
                    if (!$this->reservations->hasRemark($pay['receipt_scope_key'], $remark)) { break; }
                    $remark = null;
                }
                if ($remark === null) { throw new BusinessException('收款备注暂时无法分配', ApiResponseConstant::SERVICE_UNAVAILABLE); }
            } else {
                $used = array_flip($this->orders->usedReceiptAmounts($pay['receipt_scope_key'], $payNo, $now));
                $upper = min(Money::MAX_CENTS,
                    $original + (int) $this->getConfig('amount_offset_max', 99));
                while ($amount <= $upper && isset($used[$amount])) { $amount++; }
                if ($amount > $upper) { throw new BusinessException('当前收款账号识别金额已用尽', ApiResponseConstant::SERVICE_UNAVAILABLE); }
            }
            $this->reservations->create(['receipt_scope_key' => $pay['receipt_scope_key'], 'pay_no' => $payNo,
                'match_mode' => $mode, 'match_value' => $remark ?? (string) $amount, 'receipt_amount' => $amount,
                'valid_from' => $pay['request_at'] ?? $now, 'expire_at' => $expiresAt]);
            $ext['personal_receipt'] = array_replace($ext['personal_receipt'] ?? [], [
                'original_amount' => $original, 'receipt_amount' => $amount, 'offset_amount' => $amount - $original,
                'match_mode' => $mode, 'remark' => $remark,
            ]);
            $this->orders->saveReceiptContext($payNo, $amount, $ext, $expiresAt, $now);
            $this->businessOrders->shortenExpiry($biz['biz_no'], $expiresAt);
            return $this->orders->find($payNo) ?? throw new \RuntimeException('收款准备结果不可读取');
        });
    }

    /** 单条手机/内部通知只定位和返回证据；金额恢复与成功状态由生命周期在同一事务定稿。 */
    public function notifyPayload(array $payload): array
    {
        return $this->receiptResult($this->locateReceipt($payload['record']), $payload['record']);
    }

    /**
     * 整批流水在插件内匹配；排除本批已选订单，重复事件只处理一次，未匹配条目不返回。
     * 本方法不写库，后续确认失败可以重新输入同一批次，最终幂等由成功事务与唯一键保证。
     */
    public function matchReceipts(array $receipts): array
    {
        $results = []; $used = []; $seen = [];
        foreach ($receipts as $entry) {
            $record = $entry['record'];
            $identity = $entry['scope_key'] . ':' . ($record['trade_no'] ?? $record['event_key']);
            if (isset($seen[$identity])) { continue; }
            $seen[$identity] = true;
            try { $selected = $this->locateReceipt($record, $entry['scope_key'], $used); }
            catch (BusinessException $error) {
                if ($error->getCode() !== ApiResponseConstant::CONFLICT) { throw $error; }
                continue;
            }
            if (isset($used[$selected['pay_no']])) { continue; }
            $used[$selected['pay_no']] = true;
            $results[] = $this->receiptResult($selected, $record);
        }
        return $results;
    }

    /** @return array<string,mixed> 仅表达插件所选订单与来源证据，不代表订单已在数据库确认。 */
    private function receiptResult(PayOrder $pay, array $record): array
    {
        return ['status'=>'success','pay_no'=>$pay['pay_no'],'paid_amount'=>$pay->originalAmount(),
            'chan_order_no'=>$record['trade_no'],'chan_trade_no'=>$record['trade_no'],
            'paid_at'=>$record['paid_at'],'receipt'=>$record];
    }

    /**
     * 手机通知按当前通道的识别值定位，只有一个待付候选时才选择；重复流水返回原订单。
     *
     * @param array{amount:int,paid_at:?string,trade_no:?string,remark:?string,pay_type:string,event_key:string} $record
     */
    private function locateReceipt(array $record, ?string $scopeKey = null, array $excluded = []): PayOrder
    {
        $scope = $scopeKey ?? (string) $this->getConfig('receipt_scope_key');
        if ($scope === '') { throw new \LogicException('服务未注入收款范围'); }
        if ($record['trade_no'] !== null) {
            $known = $this->orders->successfulForTrade($scope, $record['trade_no']);
            if ($known !== null) { return $known; }
        }
        // 没有平台交易号时，稳定事件键仍可定位已经确认的原订单。
        $prepared = $this->orders->successfulForEvent($scope, $record['event_key']);
        if ($prepared !== null) { return $prepared; }
        // 使用每单准备时保存的模式；当前通道后续修改不能重新解释已展示的金额或备注。
        $candidates = $this->orders->receiptCandidates($scope, $record['amount'], $record['remark'], $record['paid_at'],
            $this->clock->now()->format('Y-m-d H:i:s'), $scopeKey === null ? $this->getConfig('channel_id') : null);
        $candidates = array_values(array_filter($candidates, static fn (PayOrder $pay): bool => !isset($excluded[$pay['pay_no']])));
        if (count($candidates) !== 1) {
            throw new BusinessException('收款消息未定位到支付单', ApiResponseConstant::CONFLICT);
        }
        return $candidates[0];
    }

    /** 监听来源不调用上游订单查询；到账仍经通知或采集确认，不以本地材料证明成功。 */
    public function query(array $order): array
    {
        return ['status' => 'pending', 'pay_no' => $order['pay_no'], 'paid_amount' => null,
            'chan_order_no' => $order['chan_order_no'] ?? null, 'chan_trade_no' => $order['chan_trade_no'] ?? null];
    }

    /** 静态码没有上游订单可撤销；结果仅供服务结束本地等待。 */
    public function close(array $order): array
    {
        return ['status' => 'closed', 'pay_no' => $order['pay_no'], 'close_semantics' => 'local_only'];
    }

    /** 码牌监听没有原路退款接口，人工退款不伪造成插件退款成功。 */
    public function refund(array $order): array { throw new UnsupportedPaymentOperationException(); }
    public function notifySuccess(): string|Response { return 'success'; }
    public function notifyFail(): string|Response { return 'fail'; }

    public function notify(Request $request): array
    {
        return $this->notifyPayload(['record' => $this->receipt($request)]);
    }

    /**
     * 保持 V2 的标题/正文选择、通知时间和 SF 监听编号语义；编号并非支付宝官方交易号。
     * SmsForwarder HMAC 不覆盖正文，平台验收仍需核对发送工具和所用通知模板。
     *
     * @return array<string,mixed> 金额为整数分，paid_at 使用上海时区。
     */
    private function receipt(Request $request): array
    {
        $message = $this->verifier->verify($request, $this->getConfig('sms_forwarder_secret'), ['com.eg.android.AlipayGphone'],
            $this->clock->now(), strtolower((string) $request->server('HTTPS', '')) === 'on' || (string) $request->server('SERVER_PORT') === '443',
            $this->getConfig('sms_forwarder_time_tolerance'));
        try { $content = json_decode($message->content, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ReceiptMessageException('invalid_message'); }
        if (!is_array($content) || !is_string($content['title'] ?? null) || !is_string($content['msg'] ?? null)) {
            throw new ReceiptMessageException('invalid_message');
        }
        $text = $content['title'] === '收款通知' ? $content['msg'] : $content['title'];
        if (!preg_match('/(?:到账|收款|成功收款|付款)\s*(\d+(?:\.\d{1,2})?)\s*元/u', $text, $amount)) {
            throw new ReceiptMessageException('unsupported_message');
        }
        $remark = preg_match('/(?:备注|留言|附言|付款备注|收款备注)[:：\s]*([0-9]{4})/u', $text, $code) ? $code[1] : null;
        $reference = 'SF' . substr(hash('sha256', $message->from . '|' . $message->transportTimestampMilliseconds . '|' . $message->content), 0, 30);
        $time = (new \DateTimeImmutable('@' . intdiv($message->transportTimestampMilliseconds, 1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        return ['pay_type' => 'alipay', 'event_key' => $reference, 'trade_no' => $reference,
            'amount' => Money::parse($amount[1]), 'paid_at' => $time->format('Y-m-d H:i:s'), 'remark' => $remark];
    }
}
