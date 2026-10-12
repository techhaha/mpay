<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\interface\ChannelNotifyInterface;
use app\common\sdk\smsforwarder\SmsForwarderVerifier;
use app\common\sdk\wechat\WechatMessageXml;
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
use DateTimeImmutable;
use DateTimeZone;
use DOMNode;
use DOMXPath;
use JsonException;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use think\facade\Db;
use think\Request;
use think\Response;

/** 微信手机及 wxtool PC 监听的独立主类；平台模板、消息身份及金额解释集中在本类。 */
final class WechatReceiptPayment implements ChannelNotifyInterface
{
    private const PAY_ACCOUNT = 'gh_3dfda90e39d6';
    private const RECEIPT_ACCOUNT = 'gh_f0a92aa7146c';

    /** @var array<string,mixed> 随代码交付的元信息和配置字段列表，不含实际账号秘密。 */
    private array $paymentInfo = [
        'code' => 'wxpay_receipt', 'name' => '微信手机/PC 收款监听', 'version' => '1.0.0', 'pay_types' => ['wxpay'],
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
        private readonly ReceiptMatchReservationRepository $reservations, private readonly Clock $clock, private readonly SmsForwarderVerifier $verifier,
        private readonly WechatMessageXml $xml)
    {
    }

    /** @var array<string,mixed> 本次实例的原配置，不能序列化给页面或日志。 */
    private array $channelConfig = [];

    public function init(#[SensitiveParameter] array $channelConfig): void
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
        $observed = $this->orders->find($payNo) ?? throw new RuntimeException('收款支付单不存在');
        return Db::transaction(function () use ($observed, $payNo): PayOrder {
            $biz = $this->businessOrders->lock($observed['biz_no']);
            $pay = $this->orders->lock($payNo);
            $this->scopes->lock($pay['receipt_scope_key']);
            if ($pay['channel_id'] !== $this->getConfig('channel_id') || $pay['biz_no'] !== $biz['biz_no']) {
                throw new LogicException('插件与支付单通道不一致');
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
            // 通用运营限额由选路服务校验；指定通道测试跳过选路，插件仍核验自身金额边界。
            if ($original < 1) {
                throw new BusinessException('付款金额必须大于零', ApiResponseConstant::VALIDATION_FAILED);
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
            return $this->orders->find($payNo) ?? throw new RuntimeException('收款准备结果不可读取');
        });
    }

    /** 单条手机/内部通知只定位和返回证据；金额恢复与成功状态由生命周期在同一事务定稿。 */
    public function notifyPayload(array $payload): array
    {
        return $this->receiptResult($this->locateReceipt($payload['record']), $payload['record']);
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
        if ($scope === '') { throw new LogicException('服务未注入收款范围'); }
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

    public function notify(Request $request): array { return $this->notifyPayload(['record' => $this->receipt($request)]); }

    /** @return array<string,mixed> 已按明确消息来源解析的标准流水，不携带平台原文。 */
    private function receipt(Request $request): array
    {
        $message = $this->verifier->verify($request, $this->getConfig('sms_forwarder_secret'),
            [self::PAY_ACCOUNT, self::RECEIPT_ACCOUNT, 'com.tencent.mm'], $this->clock->now(),
            strtolower((string) $request->server('HTTPS', '')) === 'on' || (string) $request->server('SERVER_PORT') === '443',
            $this->getConfig('sms_forwarder_time_tolerance'));
        try { $content = json_decode($message->content, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ReceiptMessageException('invalid_message'); }
        if (!is_array($content)) { throw new ReceiptMessageException('invalid_message'); }
        // 同一认证入口接收手机与 PC；认证后按明确的发送方/消息格式解析，不使用外部 source 开关。
        if (in_array($message->from, [self::PAY_ACCOUNT, self::RECEIPT_ACCOUNT], true)) { return $this->pcReceipt($message->from, $content); }
        if (!in_array($content['title'] ?? null, ['微信支付', '微信收款助手', '微信收款商业版'], true)
            || !is_string($content['msg'] ?? null)
            || !preg_match('/(?:到账|收款|收款到账)\s*[¥￥]?\s*(\d+(?:\.\d{1,2})?)\s*(?:元)?/u', $content['msg'], $amount)) {
            throw new ReceiptMessageException('unsupported_message');
        }
        $remark = preg_match('/(?:备注|留言|附言|付款备注|收款备注)[:：\s]*([0-9]{4})/u', $content['msg'], $code) ? $code[1] : null;
        // 沿用 V2 手机合同：通知时间参与监听编号及订单窗口，不称其为微信官方交易时间/交易号。
        $reference = 'SF' . substr(hash('sha256', $this->getConfig('channel_id') . '|' . $message->from . '|' . $message->transportTimestampMilliseconds . '|' . $message->content), 0, 30);
        return $this->record($reference, Money::parse($amount[1]), intdiv($message->transportTimestampMilliseconds, 1000), $remark);
    }

    /**
     * wxtool 先核对公众号、方向及消息游标，再解释 XML 模板，防止普通聊天或回退文本被当作收款。
     * 按 V2，赞赏消息使用“到账时间”，其他模板使用原公众号消息 createTime。
     *
     * @param array<string,mixed> $payload content 内的 PC 事件对象。
     * @return array<string,mixed>
     */
    private function pcReceipt(string $account, array $payload): array
    {
        $message = $payload['message'] ?? [];
        if (($payload['event'] ?? null) !== 'wxtool.officialAccount.message' || ($payload['account']['username'] ?? null) !== $account
            || ($message['username'] ?? null) !== $account || ($message['senderUsername'] ?? null) !== $account
            || ($message['source'] ?? null) !== 'message' || ($message['isFallback'] ?? false) !== false
            || ($message['direction'] ?? null) !== 'incoming' || !is_int($message['createTime'] ?? null)
            || $message['createTime'] < 1 || $message['createTime'] > $this->clock->now()->getTimestamp() + 300
            || !is_string($message['content'] ?? null)) { throw new ReceiptMessageException('invalid_message'); }
        $root = $this->xml->parse($message['content']);
        $xpath = new DOMXPath($root->ownerDocument);
        $read = static function (string $path, ?DOMNode $node = null) use ($xpath): string {
            $found = $xpath->query($path, $node);
            if ($found === false || $found->length !== 1) { throw new ReceiptMessageException('invalid_message'); }
            return trim($found->item(0)->textContent);
        };
        if ($read('/msg/fromusername') !== $account) { throw new ReceiptMessageException('source_mismatch'); }
        $title = $read('/msg/appmsg/title');
        $read('/msg/appmsg/des');
        $fields = [];
        $rows = $xpath->query('/msg/appmsg/mmreader/template_detail/line_content/topline | /msg/appmsg/mmreader/template_detail/line_content/lines/line');
        if ($rows === false || $rows->length === 0) { throw new ReceiptMessageException('invalid_message'); }
        foreach ($rows as $row) {
            $key = $read('./key/word', $row);
            if ($key === '' || isset($fields[$key])) { throw new ReceiptMessageException('invalid_message'); }
            $fields[$key] = $read('./value/word', $row);
        }
        $timestamp = $message['createTime'];
        if ($account === self::PAY_ACCOUNT) {
            $pattern = '/^二维码赞赏到账(\d+(?:\.\d{1,2})?)元(?:$|[（(])/u';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fields['到账时间'] ?? '', new DateTimeZone('Asia/Shanghai'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $fields['到账时间']) { throw new ReceiptMessageException('invalid_message'); }
            $timestamp = $date->getTimestamp();
        } elseif (str_starts_with($title, '[店员消息]')) {
            $pattern = '/^\[店员消息\]收款到账(\d+(?:\.\d{1,2})?)元$/u';
            if (!str_contains($fields['说明'] ?? '', '已存入店长') || !str_contains($fields['说明'] ?? '', '零钱')) {
                throw new ReceiptMessageException('unsupported_message');
            }
        } else {
            $pattern = '/^微信支付收款(\d+(?:\.\d{1,2})?)元(?:$|[（(])/u';
            if (!str_contains($fields['备注'] ?? '', '收款成功')
                || !str_contains($fields['备注'] ?? '', isset($fields['收款店铺']) ? '经营账户' : '零钱')) {
                throw new ReceiptMessageException('unsupported_message');
            }
        }
        if (!preg_match($pattern, $title, $titleMoney)
            || !preg_match('/^[¥￥]\s*(\d+(?:\.\d{1,2})?)\s*(?:元)?$/u', $fields['收款金额'] ?? '', $fieldMoney)
            || Money::parse($titleMoney[1]) !== Money::parse($fieldMoney[1])) { throw new ReceiptMessageException('unsupported_message'); }
        $key = $payload['delivery']['messageKey'] ?? '';
        $parts = is_string($key) ? explode(':', $key) : [];
        if (count($parts) !== 5 || !ctype_digit($parts[0]) || (int) $parts[0] < 1 || $parts[1] !== $account
            || $parts[2] !== (string) ($message['sortSeq'] ?? '') || !ctype_digit($parts[2])
            || $parts[3] !== (string) $message['createTime'] || $parts[4] !== (string) ($message['localId'] ?? '') || !ctype_digit($parts[4])) {
            throw new ReceiptMessageException('invalid_message');
        }
        $reference = 'WX' . substr(hash('sha256', $this->getConfig('channel_id') . '|' . $key), 0, 40);
        $remark = $fields['付款方备注'] ?? null;
        return $this->record($reference, Money::parse($titleMoney[1]), $timestamp, $remark === '' ? null : $remark);
    }

    /** @return array<string,mixed> 监听编号保留到标准渠道引用中，与 V2 回调去重口径一致。 */
    private function record(string $reference, int $amount, int $timestamp, ?string $remark): array
    {
        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Shanghai'));
        return ['pay_type' => 'wxpay', 'event_key' => $reference, 'trade_no' => $reference, 'amount' => $amount,
            'paid_at' => $date->format('Y-m-d H:i:s'), 'remark' => $remark];
    }
}
