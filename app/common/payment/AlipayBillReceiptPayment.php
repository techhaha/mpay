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
use app\exception\BusinessException;
use app\exception\ReceiptCollectionException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\receipt\ReceiptMatchReservationRepository;
use app\repository\payment\receipt\ReceiptScopeRepository;
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
        'code' => 'alipay_bill_receipt', 'name' => '支付宝账单收款', 'version' => '1.0.0', 'pay_types' => ['alipay'],
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
            ['type' => 'input', 'field' => 'app_id', 'title' => '应用 AppID', 'validate' => [0 => 'require', 1 => 'string', 'length' => [16, 32]]],
            ['type' => 'input', 'field' => 'bill_user_id', 'title' => '账单用户 ID', 'validate' => [0 => 'require', 1 => 'string', 'length' => [16, 32]]],
            ['type' => 'textarea', 'field' => 'private_key', 'title' => '应用私钥', 'validate' => [0 => 'require', 1 => 'string', 'length' => [64, 16384]], 'secret' => true],
            ['type' => 'textarea', 'field' => 'alipay_public_key', 'title' => '支付宝公钥', 'validate' => [0 => 'require', 1 => 'string', 'length' => [64, 16384]]],
            ['type' => 'input', 'field' => 'app_auth_token', 'title' => '应用授权凭证', 'validate' => [0 => 'string', 'length' => [1, 4096]], 'secret' => true],
        ],
    ];

    public function __construct(
        private readonly PayOrderRepository $orders,
        private readonly BizOrderRepository $businessOrders,
        private readonly ReceiptScopeRepository $scopes,
        private readonly ReceiptMatchReservationRepository $reservations,
        private readonly Clock $clock,
        private readonly AlipayBillClient $client,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {
    }

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

    public function getCode(): string { return $this->paymentInfo['code']; }
    public function getDescription(): string { return $this->paymentInfo['description'] ?? ''; }
    public function getName(): string { return $this->paymentInfo['name']; }
    public function getVersion(): string { return $this->paymentInfo['version']; }
    public function getEnabledPayTypes(): array { return $this->paymentInfo['pay_types']; }
    public function getConfigSchema(): array { return $this->paymentInfo['config_schema']; }
    public function isPreloginEnabled(): bool { return $this->paymentInfo['prelogin'] ?? false; }

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
            $candidates = array_filter($orders, static function (array $pay) use ($record, $used, $channels): bool {
                return !isset($used[$pay['pay_no']]) && isset($channels[$pay['channel_revision_id']])
                    && $pay['pay_type'] === $record['pay_type'] && $pay['receipt_amount'] === $record['amount']
                    && $pay['request_at'] <= $record['paid_at'] && $record['paid_at'] < $pay['expire_at']
                    && ($pay['match_mode'] === 'amount' || ($pay['match_mode'] === 'remark' && $pay['remark'] === $record['remark']));
            });
            if ($candidates === []) { continue; }
            $paid = strtotime($record['paid_at']);
            usort($candidates, static fn (array $a, array $b): int =>
                (abs(strtotime($a['request_at']) - $paid) <=> abs(strtotime($b['request_at']) - $paid))
                ?: strcmp($a['created_at'], $b['created_at']) ?: (strlen($a['id']) <=> strlen($b['id'])) ?: strcmp($a['id'], $b['id']));
            $pay = $candidates[0];
            $used[$pay['pay_no']] = true;
            $results[] = ['status' => 'success', 'pay_no' => $pay['pay_no'], 'paid_amount' => $pay['amount'],
                'chan_order_no' => $record['trade_no'], 'chan_trade_no' => $record['trade_no'],
                'paid_at' => $record['paid_at'], 'receipt' => $record];
        }
        return $results;
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

    /** 账单接口逐次签名，没有网页登录态；未声明 prelogin，调度器不会调用保活。 */
    public function prelogin(array $channels, DateTimeImmutable $deadline): iterable { return []; }

    /** 同账单主体的通道合并一次查询，凭证解释和查询间隔留在本插件。 */
    public function collect(array $orders, array $channels, DateTimeImmutable $deadline): iterable
    {
        $active = array_fill_keys(array_column($orders, 'channel_revision_id'), true);
        $groups = [];
        foreach ($channels as $id => $channel) {
            if (!isset($active[$id])) { continue; }
            $settings = $channel['configuration'];
            $current = $channel['current_configuration'];
            // 相同账单主体允许更新认证，不能把新收款主体的参数用于原单。
            if (is_array($current) && ($current['bill_user_id'] ?? null) === $settings['bill_user_id']) {
                foreach (['app_id', 'private_key', 'alipay_public_key', 'app_auth_token'] as $field) {
                    if (array_key_exists($field, $current)) { $settings[$field] = $current[$field]; }
                    else { unset($settings[$field]); }
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
        if ($offset !== false) { $keys = [...array_slice($keys, $offset + 1), ...array_slice($keys, 0, $offset + 1)]; }
        foreach ($keys as $key) {
            if ($this->clock->now() >= $deadline) { break; }
            $state = $store->get('alipay_bill.account.' . $key, []);
            if (!is_array($state)) { $state = []; }
            $now = $this->clock->now()->getTimestamp();
            if ((int) ($state['next_query_at'] ?? 0) > $now) { continue; }
            $group = $groups[$key];
            $store->set('alipay_bill.rotation', $key, 0);
            $selected = array_filter($orders, static fn (array $order): bool => isset($group['channels'][$order['channel_revision_id']]));
            $from = min(array_map(static fn (array $order): int => strtotime($order['request_at']), $selected));
            $to = min($now, max(array_map(static fn (array $order): int => strtotime($order['expire_at']), $selected)));
            $until = min($deadline, $this->clock->now()->modify('+' . (int) $this->config->get('receipt.account_budget_seconds', 15) . ' seconds'));
            $batch = ['channel_revision_ids' => array_map('strval', array_keys($group['channels'])), 'records' => [], 'limit_reached' => false, 'error' => null];
            try {
                $settings = array_intersect_key($group['settings'], array_flip(['app_id', 'private_key', 'alipay_public_key', 'bill_user_id', 'app_auth_token']));
                if (($settings['app_auth_token'] ?? '') === '') { unset($settings['app_auth_token']); }
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
            if ($entry['direction'] !== '收入' || $entry['amount_cents'] <= 0 || $entry['alipay_order_no'] === null) { continue; }
            Money::assertRange($entry['amount_cents']);
            $paid = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $entry['booked_at'], new DateTimeZone('Asia/Shanghai'));
            if (!$paid || $paid->format('Y-m-d H:i:s') !== $entry['booked_at']) { throw new ReceiptCollectionException('protocol_error'); }
            if ($paid->getTimestamp() < $window['from'] || $paid->getTimestamp() > $window['to']) { continue; }
            $remark = preg_match('/(?:^|\D)([0-9]{4})(?:\D|$)/u', $entry['memo'] ?? '', $code) ? $code[1] : null;
            $records[] = ['pay_type' => 'alipay', 'event_key' => $entry['alipay_order_no'], 'trade_no' => $entry['alipay_order_no'],
                'amount' => $entry['amount_cents'], 'paid_at' => $entry['booked_at'], 'remark' => $remark];
        }
        return $records;
    }
}
