<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\interface\PaymentInterface;
use app\common\sdk\wechat\WechatMessageXml;
use app\common\util\Clock;
use app\common\util\Money;
use app\common\validation\SmsForwarderValidator;
use app\exception\BusinessException;
use app\exception\ReceiptMessageException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;
use think\facade\Db;
use think\Request;
use think\Response;

/** 微信手机及 wxtool PC 监听的独立主类；平台模板、消息身份及金额解释集中在本类。 */
final class WechatReceiptPayment extends BasePayment implements PaymentInterface
{
    private const PAY_ACCOUNT = 'gh_3dfda90e39d6';
    private const RECEIPT_ACCOUNT = 'gh_f0a92aa7146c';

    /** @var array<string,mixed> 随代码交付的元信息和配置字段列表，不含实际账号秘密。 */
    protected array $paymentInfo = [
        'code' => 'wxpay_receipt',
        'name' => '微信手机/PC 收款监听',
        'version' => '1.0.0',
        'pay_types' => ['wxpay'],
        'config_schema' => [
            [
                'type' => 'select',
                'field' => 'receipt_match_mode',
                'title' => '匹配方式',
                'options' => [
                    ['value' => 'amount', 'label' => '按金额识别'],
                    ['value' => 'remark', 'label' => '按备注识别'],
                ],
                'validate' => [0 => 'require', 'in' => ['amount', 'remark']],
                'value' => 'amount',
            ],
            [
                'type' => 'number',
                'field' => 'receipt_valid_seconds',
                'title' => '付款有效期（秒）',
                'validate' => [0 => 'require', 'between' => [1, 86400]],
                'value' => 300,
            ],
            [
                'type' => 'number',
                'field' => 'amount_offset_max',
                'title' => '金额偏移上限（分）',
                'validate' => [0 => 'require', 'between' => [0, 99]],
                'value' => 99,
                'visible_when' => ['field' => 'receipt_match_mode', 'value' => 'amount'],
                'help' => '用于区分本通道内的待付订单；0 表示不增加金额。',
            ],
            [
                'type' => 'textarea',
                'field' => 'receipt_qrcode_content',
                'title' => '收款码内容',
                'value' => '',
                'validate' => [
                    0 => 'string',
                    'regex' => '/^[\s\S]{0,2331}$/D',
                    'requireWithout' => 'receipt_qrcode_image',
                ],
                'help' => '填写二维码解码后的文本，或上传收款码图片。',
            ],
            [
                'type' => 'upload',
                'field' => 'receipt_qrcode_image',
                'title' => '收款码图片',
                'value' => '',
                'help' => '支持 PNG、JPEG、WebP 图片，最大 2 MiB。',
                'validate' => [
                    0 => 'string',
                    'regex' => '/^(?:[1-9][0-9]{0,19})?$/D',
                    'requireWithout' => 'receipt_qrcode_content',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'sms_forwarder_secret',
                'title' => '通知密钥',
                'validate' => [0 => 'require', 1 => 'string', 'length' => [6, 32]],
                'secret' => true,
                'help' => '6～32 个字符，建议使用 16～32 位随机文本；与通知工具填写相同的值。',
            ],
            [
                'type' => 'number',
                'field' => 'sms_forwarder_time_tolerance',
                'title' => '通知时间容差（秒）',
                'validate' => [0 => 'require', 'between' => [5, 30]],
                'value' => 30,
                'help' =>
                    '通知请求时间与服务器接收时间允许的最大偏差，默认 30 秒；请保持设备自动校时，不影响付款有效期。',
            ],
        ],
    ];

    public function __construct(
        private readonly PayOrderRepository $orders,
        private readonly BizOrderRepository $businessOrders,
        private readonly PaymentChannelRepository $channels,
        private readonly Clock $clock,
        private readonly SmsForwarderValidator $notificationValidator,
        private readonly WechatMessageXml $xml,
    ) {}

    /** 按当前通道准备识别值并生成静态码材料；事务提交后返回 pending，不推进交易成功。 */
    public function pay(array $order): array
    {
        $prepared = $this->prepareReceipt($order['pay_no'], $order['channel_id']);
        $meta = $this->receiptMeta($prepared['ext_json']);
        return [
            'status' => 'pending',
            'pay_no' => $order['pay_no'],
            'paid_amount' => null,
            'chan_order_no' => null,
            'chan_trade_no' => null,
            'pay_type' => $order['pay_type_code'],
            'pay_product' => 'wechat_qrcode',
            'pay_action' => 'display_qrcode',
            'presentation' => [
                'pay_page' => 'page',
                'pay_params' => [
                    '_page' => 'receiptQrcode',
                    'qrcode_content' => $this->getConfig('receipt_qrcode_content') ?: null,
                    'qrcode_image_url' => $order['assets']['receipt_qrcode_image'] ?? null,
                    'instruction' => $meta['match_mode'] === 'remark' ? 'manual_remark' : 'scan',
                    'remark' => $meta['remark'],
                ],
            ],
        ];
    }

    /**
     * 先保存原金额，再把 pay_amount 改为识别金额；刷新/执行恢复复用同一份准备结果。
     * 插件由派发服务在事务外调用，本方法拥有自己的数据库事务，锁顺序与生命周期一致。
     */
    private function prepareReceipt(string $payNo, string $channelId): PayOrder
    {
        $observed = $this->orders->find($payNo) ?? throw new RuntimeException('收款支付单不存在');
        return Db::transaction(function () use ($observed, $payNo, $channelId): PayOrder {
            $biz = $this->businessOrders->lock($observed['biz_no']);
            $pay = $this->orders->lock($payNo);
            // 同一通道跨应用、跨配置版本使用同一行锁；不反向锁其他订单。
            $this->channels->lock($pay['channel_id']);
            if ($pay['channel_id'] !== $channelId || $pay['biz_no'] !== $biz['biz_no']) {
                throw new LogicException('插件与支付单通道不一致');
            }
            $ext = $pay['ext_json'] ?? [];
            if ($this->receiptMeta($ext) !== null) {
                return $pay;
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            if (
                !in_array(
                    (int) $pay['status'],
                    [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING],
                    true,
                ) ||
                $pay['expire_at'] <= $now
            ) {
                throw new BusinessException(
                    '支付单不能继续准备收款',
                    ApiResponseConstant::CONFLICT,
                );
            }
            $expiresAt = min(
                $pay['expire_at'],
                $this->clock
                    ->now()
                    ->modify('+' . $this->getConfig('receipt_valid_seconds', 300) . ' seconds')
                    ->format('Y-m-d H:i:s'),
            );
            $original = (int) $biz['order_amount'];
            Money::assertRange($original);
            $mode = $this->getConfig('receipt_match_mode', 'amount');
            if ($original < 1 || !in_array($mode, ['amount', 'remark'], true)) {
                throw new BusinessException(
                    '收款金额或匹配方式无效',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
            $amounts = [];
            $amountMode = [];
            $remarks = [];
            foreach ($this->orders->channelPending($pay['channel_id'], $payNo, $now) as $other) {
                $meta = $this->receiptMeta($other['ext_json'] ?? []);
                if ($meta === null) {
                    continue;
                }
                $amounts[$meta['receipt_amount']] = true;
                if ($meta['match_mode'] === 'amount') {
                    $amountMode[$meta['receipt_amount']] = true;
                } else {
                    $remarks[$meta['remark']] = true;
                }
            }
            $amount = $original;
            $remark = null;
            if ($mode === 'remark') {
                // 切换模式后仍尊重旧单：金额识别单不能与新备注单同额产生歧义。
                if (isset($amountMode[$original])) {
                    throw new BusinessException(
                        '该金额仍有待付款的金额识别订单，请稍后重试',
                        ApiResponseConstant::CONFLICT,
                    );
                }
                $start = random_int(1000, 9999);
                for ($offset = 0; $offset < 9000; $offset++) {
                    $candidate = (string) (1000 + (($start - 1000 + $offset) % 9000));
                    if (!isset($remarks[$candidate])) {
                        $remark = $candidate;
                        break;
                    }
                }
                if ($remark === null) {
                    throw new BusinessException(
                        '当前通道付款备注已用尽',
                        ApiResponseConstant::SERVICE_UNAVAILABLE,
                    );
                }
            } else {
                $upper = min(
                    Money::MAX_CENTS,
                    $original + (int) $this->getConfig('amount_offset_max', 99),
                );
                while ($amount <= $upper && isset($amounts[$amount])) {
                    $amount++;
                }
                if ($amount > $upper) {
                    throw new BusinessException(
                        '当前通道识别金额已用尽',
                        ApiResponseConstant::SERVICE_UNAVAILABLE,
                    );
                }
            }
            $ext['personal_receipt'] = array_replace($ext['personal_receipt'] ?? [], [
                'original_amount' => $original,
                'receipt_amount' => $amount,
                'match_mode' => $mode,
                'remark' => $remark,
            ]);
            $this->orders->saveReceiptContext($payNo, $amount, $ext, $expiresAt, $now);
            $this->businessOrders->shortenExpiry($biz['biz_no'], $expiresAt);
            return $this->orders->find($payNo) ??
                throw new RuntimeException('收款准备结果不可读取');
        });
    }

    /**
     * 本插件自己的准备快照；无识别字段表示尚未准备，部分字段缺失不能重新分配。
     * @param array<string,mixed> $extension 已按订单定位取得的扩展数据。
     * @return array{original_amount:int,receipt_amount:int,match_mode:string,remark:?string}|null
     */
    private function receiptMeta(array $extension): ?array
    {
        $meta = $extension['personal_receipt'] ?? [];
        if (!is_array($meta)) {
            throw new RuntimeException('收款准备快照无效');
        }
        if (
            array_intersect(
                ['original_amount', 'receipt_amount', 'match_mode', 'remark'],
                array_keys($meta),
            ) === []
        ) {
            return null;
        }
        if (
            !is_int($meta['original_amount'] ?? null) ||
            !is_int($meta['receipt_amount'] ?? null) ||
            $meta['original_amount'] < 1 ||
            $meta['receipt_amount'] < $meta['original_amount'] ||
            !in_array($meta['match_mode'] ?? null, ['amount', 'remark'], true) ||
            !array_key_exists('remark', $meta) ||
            ($meta['match_mode'] === 'remark' &&
                (!is_string($meta['remark']) ||
                    !preg_match('/^[0-9]{4}$/D', $meta['remark']) ||
                    $meta['receipt_amount'] !== $meta['original_amount'])) ||
            ($meta['match_mode'] === 'amount' && $meta['remark'] !== null)
        ) {
            throw new RuntimeException('收款准备快照不完整');
        }
        Money::assertRange($meta['receipt_amount']);
        return $meta;
    }

    /** @return array<string,mixed> 仅表达插件所选订单与来源证据，不代表订单已在数据库确认。 */
    private function receiptResult(
        PayOrder $pay,
        array $record,
        array $raw,
        string $receivedAt,
    ): array {
        return [
            'status' => 'success',
            'pay_no' => $pay['pay_no'],
            'paid_amount' => $pay->originalAmount(),
            'chan_order_no' =>
                $record['trade_no'] ?? ($pay['channel_order_no'] ?? $record['event_key']),
            'chan_trade_no' => $record['trade_no'],
            'pay_type' => $record['pay_type'],
            'received_amount' => $record['amount'],
            'paid_at' => $record['paid_at'] ?? ($pay['paid_at'] ?? $receivedAt),
            'event_key' => $record['event_key'],
            'raw' => $raw,
        ];
    }

    /**
     * 手机通知按当前通道的识别值定位，只有一个待付候选时才选择；重复流水返回原订单。
     *
     * @param array{amount:int,paid_at:?string,trade_no:?string,remark:?string,pay_type:string,event_key:string} $record
     */
    private function locateReceipt(array $record, string $channelId, string $receivedAt): PayOrder
    {
        if ($channelId === '') {
            throw new LogicException('服务未注入通道');
        }
        $known = $this->orders->successfulForEvent(
            $channelId,
            $this->getCode(),
            $record['event_key'],
        );
        if ($known !== null) {
            return $known;
        }
        $candidates = [];
        foreach (
            $this->orders->receiptCandidates(
                $channelId,
                $this->getCode(),
                $record['amount'],
                $record['paid_at'] ?? $receivedAt,
                $receivedAt,
            )
            as $pay
        ) {
            $meta = $this->receiptMeta($pay['ext_json'] ?? []);
            if (
                $meta !== null &&
                $meta['receipt_amount'] === $record['amount'] &&
                ($meta['match_mode'] === 'amount' || $meta['remark'] === $record['remark'])
            ) {
                $candidates[] = $pay;
                if (count($candidates) > 1) {
                    break;
                }
            }
        }
        if (count($candidates) !== 1) {
            throw new BusinessException(
                '收款消息未定位到唯一支付单',
                ApiResponseConstant::CONFLICT,
            );
        }
        return $candidates[0];
    }

    /** 监听来源不调用上游订单查询；到账仍经通知或采集确认，不以本地材料证明成功。 */
    public function query(array $order): array
    {
        return [
            'status' => 'pending',
            'pay_no' => $order['pay_no'],
            'paid_amount' => null,
            'chan_order_no' => $order['chan_order_no'] ?? null,
            'chan_trade_no' => $order['chan_trade_no'] ?? null,
        ];
    }

    /** 静态码没有上游订单可撤销；结果仅供服务结束本地等待。 */
    public function close(array $order): array
    {
        return [
            'status' => 'closed',
            'pay_no' => $order['pay_no'],
            'close_semantics' => 'local_only',
        ];
    }

    /** 码牌监听没有原路退款接口，人工退款不伪造成插件退款成功。 */
    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }
    /** 手机与 PC 监听工具共用纯文本 200；仅由服务在交易确认完成后调用。 */
    public function notifySuccess(): string|Response
    {
        return '200';
    }

    /** 业务失败由正文 400 和公开原因表达；字符串应答交由框架输出。 */
    public function notifyFail(string $message): string|Response
    {
        return '400：' . mb_strcut($message, 0, 4000, 'UTF-8');
    }

    public function notify(Request|array $payload, array $context): array
    {
        if (!($payload instanceof Request)) {
            throw new UnsupportedPaymentOperationException();
        }
        $record = $this->receipt($payload, $context['channel_id']);
        return [
            $this->receiptResult(
                $this->locateReceipt($record, $context['channel_id'], $context['received_at']),
                $record,
                $payload->post(),
                $context['received_at'],
            ),
        ];
    }

    /**
     * 按明确来源解析手机或 PC 消息，不携带平台原文。
     *
     * @return array<string,mixed> 已解析的标准流水。
     * @throws ReceiptMessageException 来源、格式或金额无效，不将技术故障转换为通知拒绝。
     */
    private function receipt(Request $request, string $channelId): array
    {
        $message = $this->notificationValidator->validateInput(
            $request->post(),
            $this->getConfig('sms_forwarder_secret'),
            [self::PAY_ACCOUNT, self::RECEIPT_ACCOUNT, 'com.tencent.mm'],
            $this->clock->now(),
            $this->getConfig('sms_forwarder_time_tolerance'),
        );
        try {
            $content = json_decode($message['content'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ReceiptMessageException('invalid_message');
        }
        if (!is_array($content)) {
            throw new ReceiptMessageException('invalid_message');
        }
        // 同一认证入口接收手机与 PC；认证后按明确的发送方/消息格式解析，不使用外部 source 开关。
        if (in_array($message['from'], [self::PAY_ACCOUNT, self::RECEIPT_ACCOUNT], true)) {
            return $this->pcReceipt($message['from'], $content, $channelId);
        }
        if (
            !in_array(
                $content['title'] ?? null,
                ['微信支付', '微信收款助手', '微信收款商业版'],
                true,
            ) ||
            !is_string($content['msg'] ?? null) ||
            !preg_match(
                '/(?:到账|收款|收款到账)\s*[¥￥]?\s*(\d+(?:\.\d{1,2})?)\s*(?:元)?/u',
                $content['msg'],
                $amount,
            )
        ) {
            throw new ReceiptMessageException('unsupported_message');
        }
        $remark = preg_match(
            '/(?:备注|留言|附言|付款备注|收款备注)[:：\s]*([0-9]{4})/u',
            $content['msg'],
            $code,
        )
            ? $code[1]
            : null;
        // 通知发送时间参与认证和稳定事件键；手机消息没有付款时间，notify 使用服务接收时间。
        $reference =
            'SF' .
            substr(
                hash(
                    'sha256',
                    $channelId .
                        '|' .
                        $message['from'] .
                        '|' .
                        $message['timestamp'] .
                        '|' .
                        $message['content'],
                ),
                0,
                30,
            );
        try {
            $cents = Money::parse($amount[1]);
        } catch (InvalidArgumentException) {
            throw new ReceiptMessageException('invalid_message');
        }
        return $this->record($reference, $cents, null, $remark);
    }

    /**
     * wxtool 先核对公众号、方向及消息游标，再解释 XML 模板，防止普通聊天或回退文本被当作收款。
     * 按 V2，赞赏消息使用“到账时间”，其他模板使用原公众号消息 createTime。
     *
     * @param array<string,mixed> $payload content 内的 PC 事件对象。
     * @return array<string,mixed>
     */
    private function pcReceipt(string $account, array $payload, string $channelId): array
    {
        $message = $payload['message'] ?? [];
        if (
            ($payload['event'] ?? null) !== 'wxtool.officialAccount.message' ||
            ($payload['account']['username'] ?? null) !== $account ||
            ($message['username'] ?? null) !== $account ||
            ($message['senderUsername'] ?? null) !== $account ||
            ($message['source'] ?? null) !== 'message' ||
            ($message['isFallback'] ?? false) !== false ||
            ($message['direction'] ?? null) !== 'incoming' ||
            !is_int($message['createTime'] ?? null) ||
            $message['createTime'] < 1 ||
            $message['createTime'] > $this->clock->now()->getTimestamp() + 300 ||
            !is_string($message['content'] ?? null)
        ) {
            throw new ReceiptMessageException('invalid_message');
        }
        $root = $this->xml->parse($message['content']);
        $xpath = new DOMXPath($root->ownerDocument);
        $read = static function (string $path, ?DOMNode $node = null) use ($xpath): string {
            $found = $xpath->query($path, $node);
            if ($found === false || $found->length !== 1) {
                throw new ReceiptMessageException('invalid_message');
            }
            return trim($found->item(0)->textContent);
        };
        if ($read('/msg/fromusername') !== $account) {
            throw new ReceiptMessageException('source_mismatch');
        }
        $title = $read('/msg/appmsg/title');
        $read('/msg/appmsg/des');
        $fields = [];
        $rows = $xpath->query(
            '/msg/appmsg/mmreader/template_detail/line_content/topline | /msg/appmsg/mmreader/template_detail/line_content/lines/line',
        );
        if ($rows === false || $rows->length === 0) {
            throw new ReceiptMessageException('invalid_message');
        }
        foreach ($rows as $row) {
            $key = $read('./key/word', $row);
            if ($key === '' || isset($fields[$key])) {
                throw new ReceiptMessageException('invalid_message');
            }
            $fields[$key] = $read('./value/word', $row);
        }
        $timestamp = $message['createTime'];
        if ($account === self::PAY_ACCOUNT) {
            $pattern = '/^二维码赞赏到账(\d+(?:\.\d{1,2})?)元(?:$|[（(])/u';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fields['到账时间'] ?? '');
            if (!$date || $date->format('Y-m-d H:i:s') !== $fields['到账时间']) {
                throw new ReceiptMessageException('invalid_message');
            }
            $timestamp = $date->getTimestamp();
        } elseif (str_starts_with($title, '[店员消息]')) {
            $pattern = '/^\[店员消息\]收款到账(\d+(?:\.\d{1,2})?)元$/u';
            if (
                !str_contains($fields['说明'] ?? '', '已存入店长') ||
                !str_contains($fields['说明'] ?? '', '零钱')
            ) {
                throw new ReceiptMessageException('unsupported_message');
            }
        } else {
            $pattern = '/^微信支付收款(\d+(?:\.\d{1,2})?)元(?:$|[（(])/u';
            if (
                !str_contains($fields['备注'] ?? '', '收款成功') ||
                !str_contains(
                    $fields['备注'] ?? '',
                    isset($fields['收款店铺']) ? '经营账户' : '零钱',
                )
            ) {
                throw new ReceiptMessageException('unsupported_message');
            }
        }
        if (
            !preg_match($pattern, $title, $titleMoney) ||
            !preg_match(
                '/^[¥￥]\s*(\d+(?:\.\d{1,2})?)\s*(?:元)?$/u',
                $fields['收款金额'] ?? '',
                $fieldMoney,
            )
        ) {
            throw new ReceiptMessageException('unsupported_message');
        }
        try {
            $cents = Money::parse($titleMoney[1]);
            $fieldCents = Money::parse($fieldMoney[1]);
        } catch (InvalidArgumentException) {
            // 只归类外部金额错误；缓存、快照等技术故障不在这里转换。
            throw new ReceiptMessageException('invalid_message');
        }
        if ($cents !== $fieldCents) {
            throw new ReceiptMessageException('unsupported_message');
        }
        $key = $payload['delivery']['messageKey'] ?? '';
        $parts = is_string($key) ? explode(':', $key) : [];
        if (
            count($parts) !== 5 ||
            !ctype_digit($parts[0]) ||
            (int) $parts[0] < 1 ||
            $parts[1] !== $account ||
            $parts[2] !== (string) ($message['sortSeq'] ?? '') ||
            !ctype_digit($parts[2]) ||
            $parts[3] !== (string) $message['createTime'] ||
            $parts[4] !== (string) ($message['localId'] ?? '') ||
            !ctype_digit($parts[4])
        ) {
            throw new ReceiptMessageException('invalid_message');
        }
        $reference = 'WX' . substr(hash('sha256', $channelId . '|' . $key), 0, 40);
        $remark = $fields['付款方备注'] ?? null;
        return $this->record($reference, $cents, $timestamp, $remark === '' ? null : $remark);
    }

    /** @return array<string,mixed> 手机/PC 消息编号只作事件键；未取得微信交易号时明确留空。 */
    private function record(string $reference, int $amount, ?int $timestamp, ?string $remark): array
    {
        return [
            'pay_type' => 'wxpay',
            'event_key' => $reference,
            'trade_no' => null,
            'amount' => $amount,
            'paid_at' => $timestamp === null ? null : date('Y-m-d H:i:s', $timestamp),
            'remark' => $remark,
        ];
    }
}
