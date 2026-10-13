<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\constant\ApiResponseConstant;
use app\common\constant\ReceiptRuntimeConstant;
use app\common\constant\TradeConstant;
use app\common\interface\ReceiptCollectorInterface;
use app\common\sdk\alipay\AlipayBillClient;
use app\common\util\Clock;
use app\common\util\Money;
use app\common\util\SafeLogger;
use app\exception\BusinessException;
use app\exception\ReceiptCollectionException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use RuntimeException;
use think\Cache;
use think\Config;
use think\facade\Db;

/** 支付宝账单插件：主类解释账务流水，SDK 负责官方请求及 RSA2，核心服务负责轮询与最终状态。 */
final class AlipayBillReceiptPayment implements ReceiptCollectorInterface
{
    /** @var array<string,mixed> 随代码交付的元信息和配置字段列表，不含实际账号秘密。 */
    private array $paymentInfo = [
        'code' => 'alipay_bill_receipt',
        'name' => '支付宝账单收款',
        'version' => '1.0.0',
        'pay_types' => ['alipay'],
        'config_schema' => [
            [
                'type' => 'select',
                'field' => 'receipt_match_mode',
                'title' => '匹配方式',
                'options' => [['value' => 'amount', 'label' => '按金额识别'], ['value' => 'remark', 'label' => '手动填写备注'], ['value' => 'alipay_transfer', 'label' => '自动填写金额和备注']],
                'validate' => [0 => 'require', 'in' => ['amount', 'remark', 'alipay_transfer']],
                'value' => 'amount'
            ],
            [
                'type' => 'number',
                'field' => 'receipt_valid_seconds',
                'title' => '付款有效期（秒）',
                'validate' => [0 => 'require', 'between' => [1, 86400]],
                'value' => 300
            ],
            [
                'type' => 'number',
                'field' => 'amount_offset_max',
                'title' => '金额偏移上限（分）',
                'validate' => [0 => 'require', 'between' => [0, 99]],
                'value' => 99,
                'visible_when' => ['field' => 'receipt_match_mode', 'value' => 'amount'],
                'help' => '用于区分本通道内的待付订单；0 表示不增加金额。'
            ],
            [
                'type' => 'textarea',
                'field' => 'receipt_qrcode_content',
                'visible_when' => ['field' => 'receipt_match_mode', 'in' => ['amount', 'remark']],
                'title' => '收款码内容',
                'value' => '',
                'validate' => [0 => 'string', 'regex' => '/^[\s\S]{0,2331}$/D', 'requireWithout' => 'receipt_qrcode_image'],
                'help' => '填写二维码解码后的文本，或上传收款码图片。'
            ],
            [
                'type' => 'upload',
                'field' => 'receipt_qrcode_image',
                'visible_when' => ['field' => 'receipt_match_mode', 'in' => ['amount', 'remark']],
                'title' => '收款码图片',
                'value' => '',
                'help' => '支持 PNG、JPEG、WebP 图片，最大 2 MiB。',
                'validate' => [0 => 'string', 'regex' => '/^(?:[1-9][0-9]{0,19})?$/D', 'requireWithout' => 'receipt_qrcode_content']
            ],
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '应用 AppID',
                'validate' => [0 => 'require', 1 => 'string', 'length' => [16, 32]]
            ],
            [
                'type' => 'input',
                'field' => 'bill_user_id',
                'title' => '账单用户 ID',
                'validate' => [0 => 'require', 1 => 'string', 'length' => [16, 32]]
            ],
            [
                'type' => 'textarea',
                'field' => 'private_key',
                'title' => '应用私钥',
                'validate' => [0 => 'require', 1 => 'string', 'length' => [64, 16384]],
                'secret' => true
            ],
            [
                'type' => 'textarea',
                'field' => 'alipay_public_key',
                'title' => '支付宝公钥',
                'validate' => [0 => 'require', 1 => 'string', 'length' => [64, 16384]]
            ],
            [
                'type' => 'input',
                'field' => 'app_auth_token',
                'title' => '应用授权凭证',
                'validate' => [0 => 'string', 'length' => [1, 4096]],
                'secret' => true
            ],
        ],
    ];

    public function __construct(
        private readonly PayOrderRepository $orders,
        private readonly BizOrderRepository $businessOrders,
        private readonly PaymentChannelRepository $channels,
        private readonly Clock $clock,
        private readonly AlipayBillClient $client,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly SafeLogger $logger,
    ) {}

    /** @var array<string,mixed> 本次实例的原配置，不能序列化给页面或日志。 */
    private array $channelConfig = [];

    public function init(array $channelConfig): void
    {
        $this->channelConfig = $channelConfig;
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->channelConfig[$key] ?? $default;
    }

    public function getCode(): string
    {
        return $this->paymentInfo['code'];
    }
    public function getDescription(): string
    {
        return $this->paymentInfo['description'] ?? '';
    }
    public function getName(): string
    {
        return $this->paymentInfo['name'];
    }
    public function getVersion(): string
    {
        return $this->paymentInfo['version'];
    }
    public function getEnabledPayTypes(): array
    {
        return $this->paymentInfo['pay_types'];
    }
    public function getConfigSchema(): array
    {
        return $this->paymentInfo['config_schema'];
    }
    public function isPreloginEnabled(): bool
    {
        return $this->paymentInfo['prelogin'] ?? false;
    }

    /** 本插件仅生成本地识别材料，原订单下重复准备会复用已持久化的金额和备注。 */
    public function canRetryPay(): bool
    {
        return true;
    }

    /** 按当前通道准备识别值并生成静态码材料；事务提交后返回 pending，不推进交易成功。 */
    public function pay(array $order): array
    {
        $prepared = $this->prepareReceipt($order['pay_no']);
        $meta = $this->receiptMeta($prepared['ext_json']);
        $params = $this->getConfig('receipt_match_mode') === 'alipay_transfer'
            ? $this->transferParams($meta['receipt_amount'], $meta['remark'])
            : ['_page' => 'receiptQrcode', 'qrcode_content' => $this->getConfig('receipt_qrcode_content') ?: null,
                'qrcode_image_url' => $this->getConfig('receipt_qrcode_image_url'),
                'instruction' => $meta['match_mode'] === 'remark' ? 'manual_remark' : 'scan', 'remark' => $meta['remark']];
        return ['status' => 'pending', 'pay_no' => $order['pay_no'], 'paid_amount' => null,
            'chan_order_no' => null, 'chan_trade_no' => null,
            'pay_type' => $order['pay_type_code'], 'pay_product' => 'receipt', 'pay_action' => 'qrcode',
            'presentation' => ['pay_page' => 'page', 'pay_params' => $params]];
    }

    /**
     * 沿用 V2 支付宝客户端入口格式，分别生成扫码与手机链接；平台规则留在本插件。
     * 参数只包含本单已准备的公开付款事实，不向页面传递账单 API 凭证。
     * @return array{_page:string,qrcode_content:string,transfer_url:string}
     */
    private function transferParams(int $cents, string $remark): array
    {
        $user = (string) $this->getConfig('bill_user_id');
        if (!preg_match('/^[0-9]{16,32}$/D', $user)) {
            throw new BusinessException('自动备注付款需要有效的支付宝用户 ID', ApiResponseConstant::VALIDATION_FAILED);
        }
        $amount = Money::format($cents);
        $scheme = 'alipays://platformapi/startapp?' . http_build_query([
            'appId' => '20000116', 'actionType' => 'toAccount', 'goBack' => 'NO',
            'amount' => $amount, 'userId' => $user, 'memo' => $remark,
        ], '', '&', PHP_QUERY_RFC3986);
        $render = 'https://render.alipay.com/p/s/i?' . http_build_query(['scheme' => $scheme], '', '&', PHP_QUERY_RFC3986);
        $middle = 'alipays://platformapi/startapp?' . http_build_query(['appId' => '20000218', 'url' => $render], '', '&', PHP_QUERY_RFC3986);
        $target = 'https://render.alipay.com/p/s/i?' . http_build_query(['scheme' => $middle], '', '&', PHP_QUERY_RFC3986);
        $mobile = 'https://render.alipay.com/p/c/mdeduct-landing?' . http_build_query(['scheme' => $target], '', '&', PHP_QUERY_RFC3986);
        $qr = 'alipays://platformapi/startapp?' . http_build_query([
            'appId' => '20000123', 'actionType' => 'scan',
            'biz_data' => json_encode(['a' => $amount, 'm' => $remark, 's' => 'money', 'u' => $user], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ], '', '&', PHP_QUERY_RFC3986);
        return ['_page' => 'alipayTransfer', 'qrcode_content' => $qr, 'transfer_url' => $mobile];
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
            // 同一通道跨应用、跨配置版本使用同一行锁；不反向锁其他订单。
            $this->channels->lock($pay['channel_id']);
            if ($pay['channel_id'] !== $this->getConfig('channel_id') || $pay['biz_no'] !== $biz['biz_no']) {
                throw new LogicException('插件与支付单通道不一致');
            }
            $ext = $pay['ext_json'] ?? [];
            if ($this->receiptMeta($ext) !== null) { return $pay; }
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            if (!in_array((int) $pay['status'], [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true)
                || $pay['expire_at'] <= $now) {
                throw new BusinessException('支付单不能继续准备收款', ApiResponseConstant::CONFLICT);
            }
            $expiresAt = min($pay['expire_at'], $this->clock->now()->modify('+' . $this->getConfig('receipt_valid_seconds', 300) . ' seconds')->format('Y-m-d H:i:s'));
            $original = (int) $biz['order_amount'];
            Money::assertRange($original);
            $mode = ($this->getConfig('receipt_match_mode', 'amount') === 'alipay_transfer' ? 'remark' : $this->getConfig('receipt_match_mode', 'amount'));
            if ($original < 1 || !in_array($mode, ['amount', 'remark'], true)) {
                throw new BusinessException('收款金额或匹配方式无效', ApiResponseConstant::VALIDATION_FAILED);
            }
            $amounts = []; $amountMode = []; $remarks = [];
            foreach ($this->orders->channelPending($pay['channel_id'], $payNo, $now) as $other) {
                $meta = $this->receiptMeta($other['ext_json'] ?? []);
                if ($meta === null) { continue; }
                $amounts[$meta['receipt_amount']] = true;
                if ($meta['match_mode'] === 'amount') { $amountMode[$meta['receipt_amount']] = true; }
                else { $remarks[$meta['remark']] = true; }
            }
            $amount = $original; $remark = null;
            if ($mode === 'remark') {
                // 切换模式后仍尊重旧单：金额识别单不能与新备注单同额产生歧义。
                if (isset($amountMode[$original])) {
                    throw new BusinessException('该金额仍有待付款的金额识别订单，请稍后重试', ApiResponseConstant::CONFLICT);
                }
                $start = random_int(1000, 9999);
                for ($offset = 0; $offset < 9000; $offset++) {
                    $candidate = (string) (1000 + ($start - 1000 + $offset) % 9000);
                    if (!isset($remarks[$candidate])) { $remark = $candidate; break; }
                }
                if ($remark === null) { throw new BusinessException('当前通道付款备注已用尽', ApiResponseConstant::SERVICE_UNAVAILABLE); }
            } else {
                $upper = min(Money::MAX_CENTS, $original + (int) $this->getConfig('amount_offset_max', 99));
                while ($amount <= $upper && isset($amounts[$amount])) { $amount++; }
                if ($amount > $upper) { throw new BusinessException('当前通道识别金额已用尽', ApiResponseConstant::SERVICE_UNAVAILABLE); }
            }
            $ext['personal_receipt'] = array_replace($ext['personal_receipt'] ?? [], [
                'original_amount' => $original, 'receipt_amount' => $amount, 'offset_amount' => $amount - $original,
                'match_mode' => $mode, 'remark' => $remark,
            ]);
            $this->orders->saveReceiptContext($payNo, $amount, $ext, $expiresAt, $now);
            $this->businessOrders->shortenExpiry($biz['biz_no'], $expiresAt);
            return $this->orders->find($payNo) ?? throw new RuntimeException('收款准备结果不可读取');
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
        if (!is_array($meta)) { throw new RuntimeException('收款准备快照无效'); }
        if (array_intersect(['original_amount', 'receipt_amount', 'match_mode', 'remark'], array_keys($meta)) === []) { return null; }
        if (!is_int($meta['original_amount'] ?? null) || !is_int($meta['receipt_amount'] ?? null)
            || $meta['original_amount'] < 1 || $meta['receipt_amount'] < $meta['original_amount']
            || !in_array($meta['match_mode'] ?? null, ['amount', 'remark'], true)
            || !array_key_exists('remark', $meta)
            || ($meta['match_mode'] === 'remark' && (!is_string($meta['remark']) || !preg_match('/^[0-9]{4}$/D', $meta['remark'])
                || $meta['receipt_amount'] !== $meta['original_amount']))
            || ($meta['match_mode'] === 'amount' && $meta['remark'] !== null)) {
            throw new RuntimeException('收款准备快照不完整');
        }
        Money::assertRange($meta['receipt_amount']);
        return $meta;
    }

    /**
     * 一次匹配本响应全部记录，只使用服务提供的原单集合；已消费记录不重新分配。
     * 付款在原窗口内但响应迟到仍可定位；最终状态和幂等由生命周期锁内复核。
     */
    public function matchReceipts(array $receipts, array $orders, array $channels): array
    {
        usort($receipts, static fn (array $a, array $b): int =>
            strcmp($a['record']['paid_at'] ?? '', $b['record']['paid_at'] ?? '')
            ?: strcmp($a['record']['event_key'], $b['record']['event_key']));
        $results = []; $used = []; $seen = [];
        foreach ($receipts as $entry) {
            $record = $entry['record'];
            if ($entry['consumed_pay_no'] !== null || isset($seen[$record['event_key']]) || $record['paid_at'] === null) { continue; }
            $seen[$record['event_key']] = true;
            $byChannel = [];
            foreach ($orders as $pay) {
                $channel = $channels[$pay['channel_revision_id']] ?? null;
                if ($channel === null || $channel['channel_id'] !== $pay['channel_id']
                    || $pay['pay_type'] !== $record['pay_type']) { continue; }
                $meta = $this->receiptMeta($pay['ext_json'] ?? []);
                if ($meta === null || $meta['receipt_amount'] !== $record['amount']
                    || $pay['request_at'] > $record['paid_at'] || $record['paid_at'] >= $pay['expire_at']
                    || ($meta['match_mode'] === 'remark' && $meta['remark'] !== $record['remark'])) { continue; }
                $byChannel[$pay['channel_id']][] = $pay;
            }
            // 同账号请求可合并，订单范围不能合并；无通道证据时绝不靠遍历顺序抢单。
            // 已被本批选中的订单也参与归属判断，避免后续流水假装只剩一个通道。
            if (count($byChannel) > 1) {
                $this->logger->record('receipt.match', ['plugin_code' => $this->getCode(), 'reason_code' => 'ambiguous_channel'], 'warning');
                continue;
            }
            $candidates = array_values(array_filter($byChannel === [] ? [] : reset($byChannel),
                static fn (array $pay): bool => !isset($used[$pay['pay_no']])));
            if ($candidates === []) { continue; }
            $paid = strtotime($record['paid_at']);
            usort($candidates, static fn (array $a, array $b): int =>
                (abs(strtotime($a['request_at']) - $paid) <=> abs(strtotime($b['request_at']) - $paid))
                ?: strcmp($a['created_at'], $b['created_at']) ?: (strlen($a['id']) <=> strlen($b['id'])) ?: strcmp($a['id'], $b['id']));
            $pay = $candidates[0]; $meta = $this->receiptMeta($pay['ext_json']);
            $used[$pay['pay_no']] = true;
            $results[] = ['status' => 'success', 'pay_no' => $pay['pay_no'], 'paid_amount' => $meta['original_amount'],
                'chan_order_no' => $record['trade_no'], 'chan_trade_no' => $record['trade_no'],
                'paid_at' => $record['paid_at'], 'receipt' => $record];
        }
        return $results;
    }

    /** 监听来源不调用上游订单查询；到账仍经通知或采集确认，不以本地材料证明成功。 */
    public function query(array $order): array
    {
        return [
            'status' => 'pending',
            'pay_no' => $order['pay_no'],
            'paid_amount' => null,
            'chan_order_no' => $order['chan_order_no'] ?? null,
            'chan_trade_no' => $order['chan_trade_no'] ?? null
        ];
    }

    /** 静态码没有上游订单可撤销；结果仅供服务结束本地等待。 */
    public function close(array $order): array
    {
        return ['status' => 'closed', 'pay_no' => $order['pay_no'], 'close_semantics' => 'local_only'];
    }

    /** 码牌监听没有原路退款接口，人工退款不伪造成插件退款成功。 */
    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }

    /** 账单接口逐次签名，没有网页登录态；未声明 prelogin，调度器不会调用保活。 */
    public function prelogin(array $channels, DateTimeImmutable $deadline): iterable
    {
        return [];
    }

    /** 同账单主体的通道合并一次查询，凭证解释和查询间隔留在本插件。 */
    public function collect(array $orders, array $channels, DateTimeImmutable $deadline): iterable
    {
        $orders = array_filter($orders, fn (array $pay): bool => $this->receiptMeta($pay['ext_json'] ?? []) !== null);
        $active = array_fill_keys(array_column($orders, 'channel_revision_id'), true);
        $groups = [];
        foreach ($channels as $id => $channel) {
            if (!isset($active[$id])) {
                continue;
            }
            $settings = $channel['configuration'];
            $current = $channel['current_configuration'];
            // 相同账单主体允许更新认证，不能把新收款主体的参数用于原单。
            if (is_array($current) && ($current['bill_user_id'] ?? null) === $settings['bill_user_id']) {
                foreach (['app_id', 'private_key', 'alipay_public_key', 'app_auth_token'] as $field) {
                    if (array_key_exists($field, $current)) {
                        $settings[$field] = $current[$field];
                    } else {
                        unset($settings[$field]);
                    }
                }
            }
            $key = hash('sha256', $this->getCode() . ':' . $settings['bill_user_id']);
            $groups[$key]['channels'][(string) $id] = $channel;
            $groups[$key]['settings'] ??= $settings;
        }
        ksort($groups);
        $store = $this->cache->store('receipt');
        $keys = array_keys($groups);
        $last = $store->get('alipay_bill.rotation');
        $offset = is_string($last) ? array_search($last, $keys, true) : false;
        if ($offset !== false) {
            $keys = [...array_slice($keys, $offset + 1), ...array_slice($keys, 0, $offset + 1)];
        }
        foreach ($keys as $key) {
            if ($this->clock->now() >= $deadline) {
                break;
            }
            $state = $store->get('alipay_bill.account.' . $key, []);
            if (!is_array($state)) {
                $state = [];
            }
            $now = $this->clock->now()->getTimestamp();
            if ((int) ($state['next_query_at'] ?? 0) > $now) {
                continue;
            }
            $group = $groups[$key];
            $store->set('alipay_bill.rotation', $key, 0);
            $selected = array_filter($orders, static fn(array $order): bool => isset($group['channels'][$order['channel_revision_id']]));
            $from = min(array_map(static fn(array $order): int => strtotime($order['request_at']), $selected));
            $to = min($now, max(array_map(static fn(array $order): int => strtotime($order['expire_at']), $selected)));
            $until = min($deadline, $this->clock->now()->modify('+' . (int) $this->config->get('receipt.account_budget_seconds', 15) . ' seconds'));
            $batch = ['channel_revision_ids' => array_map('strval', array_keys($group['channels'])), 'records' => [], 'limit_reached' => false, 'error' => null];
            try {
                $settings = array_intersect_key($group['settings'], array_flip(['app_id', 'private_key', 'alipay_public_key', 'bill_user_id', 'app_auth_token']));
                if (($settings['app_auth_token'] ?? '') === '') {
                    unset($settings['app_auth_token']);
                }
                $data = $this->client->query($settings, $from, $to, $until);
                $batch['records'] = $this->normalizeBill($data, ['from' => $from, 'to' => $to]);
                $batch['limit_reached'] = $data['limit_reached'];
                $state['failures'] = 0;
                $delay = (int) $this->config->get('receipt.query_interval_seconds', 3);
            } catch (ReceiptCollectionException $error) {
                $state['failures'] = min(10, (int) ($state['failures'] ?? 0) + 1);
                $delay = $error->retryAfterSeconds ?? min(300, 30 * (2 ** min(4, $state['failures'] - 1)));
                $batch['error'] = ['code' => ReceiptRuntimeConstant::failure($error->reasonCode), 'reason' => $error->reasonCode, 'retry_after' => $delay];
            }
            $state['next_query_at'] = $this->clock->now()->getTimestamp() + $delay;
            $store->set('alipay_bill.account.' . $key, $state, 0);
            yield $batch;
        }
    }

    /** V2 以入账时间匹配付款窗口；收入分录仍须具有真实平台交易号。 */
    private function normalizeBill(array $data, array $window): array
    {
        $records = [];
        foreach ($data['entries'] as $entry) {
            if ($entry['direction'] !== '收入' || $entry['amount_cents'] <= 0 || $entry['alipay_order_no'] === null) {
                continue;
            }
            Money::assertRange($entry['amount_cents']);
            $paid = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $entry['booked_at'], new DateTimeZone('Asia/Shanghai'));
            if (!$paid || $paid->format('Y-m-d H:i:s') !== $entry['booked_at']) {
                throw new ReceiptCollectionException('protocol_error');
            }
            if ($paid->getTimestamp() < $window['from'] || $paid->getTimestamp() > $window['to']) {
                continue;
            }
            $remark = preg_match('/(?:^|\D)([0-9]{4})(?:\D|$)/u', $entry['memo'] ?? '', $code) ? $code[1] : null;
            $records[] = [
                'pay_type' => 'alipay',
                'event_key' => $entry['alipay_order_no'],
                'trade_no' => $entry['alipay_order_no'],
                'amount' => $entry['amount_cents'],
                'paid_at' => $entry['booked_at'],
                'remark' => $remark
            ];
        }
        return $records;
    }
}
