<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\interface\PaymentInterface;
use app\common\sdk\bank\BankReceiptMessage;
use app\common\util\Clock;
use app\common\util\Money;
use app\common\validation\SmsForwarderValidator;
use app\exception\BusinessException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use RuntimeException;
use think\facade\Db;
use think\Request;
use think\Response;

/** 中国银行微银行入账通知适配；独立负责识别准备及匹配，最终到账由统一生命周期提交。 */
final class BankOfChinaReceiptPayment extends BasePayment implements PaymentInterface
{
    private const BANK_NAME = '中国银行';
    private const OFFICIAL_ACCOUNT = 'gh_19b52c2473d6';

    /** @var array<string,mixed> 银行专属说明和通用字段声明，不包含实际卡号或密钥。 */
    protected array $paymentInfo = [
        'code' => 'bank_of_china_receipt',
        'name' => '中国银行银行卡收款',
        'version' => '1.0.0',
        'pay_types' => ['bank'],
        'description' => '通过 wxtool 转发中国银行微银行的完整入账消息确认收款。请按应付金额精确转账；不支持备注识别。请为同一张卡使用一个收款通道，多应用可共用该通道。',
        'config_schema' => [
            [
                'type' => 'input',
                'field' => 'account_name',
                'title' => '收款户名',
                'validate' => [0 => 'require', 1 => 'string', 'regex' => '/^[^\x00-\x1f\x7f]{1,100}$/uD'],
                'help' => '收银台向付款人展示的收款户名，请与银行卡实际开户信息一致。',
            ],
            [
                'type' => 'input',
                'field' => 'card_number',
                'title' => '收款银行卡号',
                'validate' => [0 => 'require', 1 => 'string', 'regex' => '/^[0-9](?:[ -]?[0-9]){11,18}$/D'],
                'help' => '12～19 位卡号，可使用空格或短横线分组；收银台会向付款人展示完整卡号。',
            ],
            [
                'type' => 'number',
                'field' => 'receipt_valid_seconds',
                'title' => '付款有效期（秒）',
                'validate' => [0 => 'require', 'between' => [60, 1800]],
                'value' => 300,
                'help' => '本单提示转账的有效时间，重复打开不会延长。',
            ],
            [
                'type' => 'number',
                'field' => 'amount_offset_max',
                'title' => '金额偏移上限（分）',
                'validate' => [0 => 'require', 'between' => [0, 99]],
                'value' => 99,
                'help' => '用于区分本通道内的待付订单；0 表示不增加金额，请严格按页面应付金额转账。',
            ],
            [
                'type' => 'input',
                'field' => 'sms_forwarder_secret',
                'title' => '通知密钥',
                'validate' => [0 => 'require', 1 => 'string', 'length' => [6, 32]],
                'secret' => true,
                'help' => '6～32 个字符，与监听工具中当前通知通道填写的值一致。',
            ],
            [
                'type' => 'number',
                'field' => 'sms_forwarder_time_tolerance',
                'title' => '通知时间容差（秒）',
                'validate' => [0 => 'require', 'between' => [5, 30]],
                'value' => 30,
                'help' => '通知请求时间与服务器接收时间的允许偏差；不改变银行交易时间或付款有效期。',
            ],
        ],
    ];

    public function __construct(
        private readonly PayOrderRepository $orders,
        private readonly BizOrderRepository $businessOrders,
        private readonly PaymentChannelRepository $channels,
        private readonly Clock $clock,
        private readonly SmsForwarderValidator $notificationValidator,
        private readonly BankReceiptMessage $messages,
    ) {}

    /** 只生成公开转账资料；识别金额及期限复用原单事实，不输出密钥或完整配置。 */
    public function pay(array $order): array
    {
        $accountName = trim($this->getConfig('account_name'));
        $cardNumber = $this->configuredCardNumber();
        if ($accountName === '' || mb_strlen($accountName) > 100) {
            throw new BusinessException('收款户名配置无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        $this->prepareReceipt($order['pay_no'], $order['channel_id']);
        return [
            'status' => 'pending',
            'pay_no' => $order['pay_no'],
            'paid_amount' => null,
            'chan_order_no' => null,
            'chan_trade_no' => null,
            'pay_type' => $order['pay_type_code'],
            'pay_product' => 'bank_transfer',
            'pay_action' => 'display_bank_account',
            'presentation' => [
                'pay_page' => 'page',
                'pay_params' => [
                    '_page' => 'bankTransfer',
                    'bank_name' => self::BANK_NAME,
                    'account_name' => $accountName,
                    'card_number' => $cardNumber,
                ],
            ],
        ];
    }

    /** 在 Biz → Pay → Channel 锁内分配通道内唯一金额；完整快照直接复用，损坏时拒绝重分配。 */
    private function prepareReceipt(string $payNo, string $channelId): void
    {
        $observed = $this->orders->find($payNo) ?? throw new RuntimeException('收款支付单不存在');
        Db::transaction(function () use ($observed, $payNo, $channelId): void {
            $biz = $this->businessOrders->lock($observed['biz_no']);
            $pay = $this->orders->lock($payNo);
            $this->channels->lock($pay['channel_id']);
            if ($pay['channel_id'] !== $channelId || $pay['biz_no'] !== $biz['biz_no']) {
                throw new LogicException('银行卡插件与支付单通道不一致');
            }
            $meta = $this->receiptMeta($pay['ext_json'] ?? []);
            if ($meta !== null) {
                return;
            }
            $now = $this->clock->now();
            $time = $now->format('Y-m-d H:i:s');
            if (
                !in_array((int) $pay['status'], [
                    TradeConstant::ORDER_STATUS_CREATED,
                    TradeConstant::ORDER_STATUS_PAYING,
                ], true) ||
                $pay['expire_at'] <= $time
            ) {
                throw new BusinessException('支付单不能继续准备收款', ApiResponseConstant::CONFLICT);
            }
            $original = (int) $biz['order_amount'];
            Money::assertRange($original);
            if ($original < 1) {
                throw new BusinessException('付款金额必须大于零', ApiResponseConstant::VALIDATION_FAILED);
            }
            $used = [];
            foreach ($this->orders->channelPending($channelId, $payNo, $time) as $other) {
                $otherMeta = $this->receiptMeta($other['ext_json'] ?? []);
                if ($otherMeta !== null) {
                    $used[$otherMeta['receipt_amount']] = true;
                }
            }
            $amount = $original;
            $upper = min(Money::MAX_CENTS, $original + $this->getConfig('amount_offset_max'));
            while ($amount <= $upper && isset($used[$amount])) {
                $amount++;
            }
            if ($amount > $upper) {
                throw new BusinessException('当前通道识别金额已用尽', ApiResponseConstant::SERVICE_UNAVAILABLE);
            }
            $expiresAt = min(
                $pay['expire_at'],
                $now->modify('+' . $this->getConfig('receipt_valid_seconds') . ' seconds')->format('Y-m-d H:i:s'),
            );
            $extension = $pay['ext_json'] ?? [];
            // 只补充识别字段，保留建单时冻结的配置/插件版本及测试身份。
            $extension['personal_receipt'] = array_replace($extension['personal_receipt'] ?? [], [
                'original_amount' => $original,
                'receipt_amount' => $amount,
                'match_mode' => 'amount',
                'remark' => null,
            ]);
            $this->orders->saveReceiptContext($payNo, $amount, $extension, $expiresAt, $time);
            $this->businessOrders->shortenExpiry($biz['biz_no'], $expiresAt);
        });
    }

    /** @return array{original_amount:int,receipt_amount:int,match_mode:string,remark:null}|null */
    private function receiptMeta(array $extension): ?array
    {
        $meta = $extension['personal_receipt'] ?? [];
        if (!is_array($meta)) {
            throw new RuntimeException('银行卡识别快照无效');
        }
        if (array_intersect(['original_amount', 'receipt_amount', 'match_mode', 'remark'], array_keys($meta)) === []) {
            return null;
        }
        if (
            !is_int($meta['original_amount'] ?? null) || $meta['original_amount'] < 1 ||
            !is_int($meta['receipt_amount'] ?? null) || $meta['receipt_amount'] < $meta['original_amount'] ||
            ($meta['match_mode'] ?? null) !== 'amount' ||
            !array_key_exists('remark', $meta) || $meta['remark'] !== null
        ) {
            throw new RuntimeException('银行卡识别快照无效');
        }
        Money::assertRange($meta['receipt_amount']);
        return $meta;
    }

    private function configuredCardNumber(): string
    {
        $number = str_replace([' ', '-'], '', $this->getConfig('card_number'));
        if (!preg_match('/^[0-9]{12,19}$/D', $number)) {
            throw new BusinessException('收款银行卡号配置无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        return $number;
    }

    /**
     * 四字段认证、银行来源、入账方向及卡尾核对后只返回本通道唯一匹配结果。
     * 仅明确的非入账消息返回 []；入账不匹配或歧义必须报错，不能假装已处理成功。
     */
    public function notify(Request|array $payload, array $context): array
    {
        if (!$payload instanceof Request) {
            throw new UnsupportedPaymentOperationException();
        }
        $receivedAt = $this->clock->now();
        $input = $this->notificationValidator->validateInput(
            $payload->post(),
            $this->getConfig('sms_forwarder_secret'),
            [self::OFFICIAL_ACCOUNT],
            $receivedAt,
            $this->getConfig('sms_forwarder_time_tolerance'),
        );
        $message = $this->messages->parse($input['content'], self::OFFICIAL_ACCOUNT, $receivedAt->getTimestamp());
        if ($message === null) {
            return [];
        }
        $receipt = $this->parseBankDescription($message['title'], $message['description'], $message['message_time']);
        if ($receipt === null) {
            return [];
        }
        if (!hash_equals(substr($this->configuredCardNumber(), -4), $receipt['card_tail'])) {
            throw new BusinessException('银行入账卡尾号与当前通道不一致', ApiResponseConstant::CONFLICT);
        }
        if ($message['message_time'] < $receipt['event_at'] || $message['message_time'] - $receipt['event_at'] > 300) {
            throw new BusinessException('银行交易时间与公众号消息时间不一致', ApiResponseConstant::VALIDATION_FAILED);
        }
        $zone = new DateTimeZone('Asia/Shanghai');
        $date = $receivedAt->setTimezone($zone);
        $record = [
            'pay_type' => 'bank',
            'event_key' => $message['event_key'],
            'trade_no' => null,
            'amount' => $receipt['amount'],
            'paid_at' => $date->setTimestamp($receipt['event_at'])->format('Y-m-d H:i:s'),
            'remark' => null,
        ];
        // 分钟时间表示该分钟的区间；同时限制微信原消息必须在识别开始后、结束至多 300 秒内。
        $windowStart = $date->setTimestamp(max(
            $receipt['event_at'],
            $message['message_time'] - 300,
        ))->format('Y-m-d H:i:s');
        $windowEnd = $date->setTimestamp(min(
            $receipt['event_at'] + $receipt['precision_seconds'] - 1,
            $message['message_time'],
        ))->format('Y-m-d H:i:s');
        $pay = $this->orders->successfulForEvent($context['channel_id'], $this->getCode(), $record['event_key']);
        if ($pay !== null) {
            if (!$this->matchesReceipt($pay, $record['amount'], $windowStart, $windowEnd)) {
                throw new BusinessException('重复银行消息与原订单不一致', ApiResponseConstant::CONFLICT);
            }
        } else {
            $matches = [];
            foreach ($this->orders->receiptCandidates(
                $context['channel_id'],
                $this->getCode(),
                $record['amount'],
                $windowStart,
                $date->format('Y-m-d H:i:s'),
                $windowEnd,
            ) as $candidate) {
                if ($this->matchesReceipt($candidate, $record['amount'], $windowStart, $windowEnd)) {
                    $matches[] = $candidate;
                    if (count($matches) > 1) {
                        break;
                    }
                }
            }
            if (count($matches) !== 1) {
                throw new BusinessException('银行入账未匹配到唯一支付单', ApiResponseConstant::CONFLICT);
            }
            $pay = $matches[0];
        }
        return [[
            'status' => 'success',
            'pay_no' => $pay['pay_no'],
            'paid_amount' => $pay->originalAmount(),
            'chan_order_no' => null,
            'chan_trade_no' => null,
            'receipt' => $record,
        ]];
    }

    /** 原单窗口与识别金额同时成立才匹配；不按当前通道参数重新计算原单金额或期限。 */
    private function matchesReceipt(
        PayOrder $pay,
        int $amount,
        string $windowStart,
        string $windowEnd,
    ): bool {
        $meta = $this->receiptMeta($pay['ext_json'] ?? []);
        return $meta !== null &&
            $meta['receipt_amount'] === $amount &&
            $pay['request_at'] !== null &&
            $pay['request_at'] <= $windowEnd &&
            $pay['expire_at'] > $windowStart;
    }

    /** 银行公众号监听没有上游查单接口；到账只能由通知或管理员核实。 */
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

    /** 本地关闭不能使银行账号失去转账能力，状态变更仍由生命周期完成。 */
    public function close(array $order): array
    {
        return ['status' => 'closed', 'pay_no' => $order['pay_no'], 'close_semantics' => 'local_only'];
    }

    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }

    public function notifySuccess(): string|Response
    {
        return '200';
    }

    public function notifyFail(string $message): string|Response
    {
        return '400：' . mb_strcut($message, 0, 4000, 'UTF-8');
    }

    /** @return array{amount:int,card_tail:string,event_at:int,precision_seconds:int}|null */
    private function parseBankDescription(string $title, string $description, int $messageTime): ?array
    {
        if ($title !== '交易成功提醒') {
            return null;
        }
        $type = $this->messages->field($description, '交易类型');
        if (preg_match('/^二维码消费（尾号[0-9]{4}）$/uD', $type)) {
            return null;
        }
        if (!preg_match('/^银联入账（尾号([0-9]{4})）$/uD', $type, $tail)) {
            throw new BusinessException('中国银行交易类型尚未验证为入账', ApiResponseConstant::VALIDATION_FAILED);
        }
        if (!preg_match('/^人民币\s*(.+)$/uD', $this->messages->field($description, '交易金额'), $money)) {
            throw new BusinessException('中国银行入账币种或金额格式无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        $time = $this->messages->optionalField($description, '交易时间');
        return [
            'amount' => $this->messages->amount($money[1]),
            'card_tail' => $tail[1],
            'event_at' => $time === null ? $messageTime : $this->yearlessTime($time, $messageTime),
            'precision_seconds' => $time === null ? 1 : 60,
        ];
    }

    /** 中行专属无年份格式：使用微信原消息的相邻年份选择最近的有效日期。 */
    private function yearlessTime(string $value, int $messageTime): int
    {
        if (!preg_match('/^(\d{1,2})月(\d{1,2})日\s*(\d{1,2}):(\d{2})$/uD', $value, $match)) {
            throw new BusinessException('中国银行交易时间格式无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        $year = (int) (new DateTimeImmutable('@' . $messageTime))->setTimezone(
            new DateTimeZone('Asia/Shanghai'),
        )->format('Y');
        $candidates = [];
        foreach ([$year - 1, $year, $year + 1] as $candidate) {
            if (checkdate((int) $match[1], (int) $match[2], $candidate)) {
                $candidates[] = $this->messages->timestamp(
                    $candidate,
                    (int) $match[1],
                    (int) $match[2],
                    (int) $match[3],
                    (int) $match[4],
                    0,
                );
            }
        }
        if ($candidates === []) {
            throw new BusinessException('中国银行交易日期无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        usort($candidates, static fn(int $left, int $right): int =>
            abs($messageTime - $left) <=> abs($messageTime - $right));
        return $candidates[0];
    }
}
