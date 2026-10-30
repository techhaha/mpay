<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\interface\ReceiptCollectorInterface;
use app\common\sdk\shouqianba\ShouQianBaClient;
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
use think\Request;
use think\Response;
use think\Cache;
use think\Config;
use think\facade\Db;

/** 收钱吧码牌插件：SDK 处理登录及交易页，本类解释成功状态、原交易金额、方式、终端和备注。 */
final class ShouQianBaReceiptPayment extends BasePayment implements ReceiptCollectorInterface
{
    /** @var array<string,mixed> 随代码交付的元信息和配置字段列表，不含实际账号秘密。 */
    protected array $paymentInfo = [
        'code' => 'shouqianba_receipt',
        'name' => '收钱吧码牌收款',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'prelogin' => true,
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
                'field' => 'receipt_watcher_query_interval_seconds',
                'title' => '账号查询间隔（秒）',
                'validate' => [0 => 'require', 'between' => [2, 300]],
                'value' => 3,
                'help' => '同账号正常查询结束后的等待时间；失败仍按插件退避，不影响付款有效期。',
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
                'field' => 'watcher_username',
                'title' => '商户后台账号',
                'validate' => [0 => 'require', 1 => 'string', 'length' => [1, 128]],
            ],
            [
                'type' => 'input',
                'field' => 'watcher_password',
                'title' => '商户后台密码',
                'validate' => [0 => 'require', 1 => 'string', 'length' => [1, 256]],
                'secret' => true,
            ],
            [
                'type' => 'input',
                'field' => 'receipt_account_no',
                'title' => '收款商户或门店编号',
                'validate' => [0 => 'require', 1 => 'string', 'length' => [1, 128]],
            ],
            [
                'type' => 'input',
                'field' => 'receipt_merchant_name',
                'title' => '码牌商户名称',
                'validate' => [0 => 'string', 'length' => [0, 128]],
                'value' => '',
            ],
            [
                'type' => 'input',
                'field' => 'receipt_terminal_no',
                'title' => '收款终端号',
                'validate' => [0 => 'string', 'length' => [0, 128]],
                'value' => '',
                'help' =>
                    '支持终端编号或码牌指纹；留空时从收钱吧码牌链接读取指纹，无法读取时不限定终端。',
            ],
        ],
    ];

    public function __construct(
        private readonly PayOrderRepository $orders,
        private readonly BizOrderRepository $businessOrders,
        private readonly PaymentChannelRepository $channels,
        private readonly Clock $clock,
        private readonly ShouQianBaClient $client,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly SafeLogger $logger,
    ) {}

    public function isPreloginEnabled(): bool
    {
        return $this->paymentInfo['prelogin'] ?? false;
    }

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
            'pay_product' => 'shouqianba_qrcode',
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

    /**
     * 当前通道整批匹配；复用本轮调度的候选快照，已消费记录不重新分配。
     * 付款在原窗口内但响应迟到仍可定位；最终状态和幂等由生命周期锁内复核。
     */
    public function notify(Request|array $payload, array $context): array
    {
        if ($payload instanceof Request) {
            throw new UnsupportedPaymentOperationException();
        }
        $receipts = $payload['records'];
        $orders = $payload['orders'];
        usort(
            $receipts,
            static fn(array $a, array $b): int => strcmp(
                $a['record']['paid_at'] ?? '',
                $b['record']['paid_at'] ?? '',
            ) ?:
            strcmp($a['record']['event_key'], $b['record']['event_key']),
        );
        $results = [];
        $used = [];
        $seen = [];
        // 本批按需解析候选，每笔订单的识别信息和时间只计算一次；不跨批次缓存。
        $prepared = [];
        foreach ($receipts as $entry) {
            $record = $entry['record'];
            if (
                $entry['consumed_pay_no'] !== null ||
                isset($seen[$record['event_key']]) ||
                $record['paid_at'] === null
            ) {
                continue;
            }
            $seen[$record['event_key']] = true;
            $byChannel = [];
            foreach ($orders as $pay) {
                if (
                    ($pay['ext_json']['payment_context']['pay_type'] ?? null) !==
                    $record['pay_type']
                ) {
                    continue;
                }
                if (!array_key_exists($pay['pay_no'], $prepared)) {
                    $prepared[$pay['pay_no']] = [
                        'meta' => $this->receiptMeta($pay['ext_json'] ?? []),
                        'requested_at' => strtotime($pay['request_at']),
                    ];
                }
                $meta = $prepared[$pay['pay_no']]['meta'];
                if (
                    $meta === null ||
                    $meta['receipt_amount'] !== $record['amount'] ||
                    $pay['request_at'] > $record['paid_at'] ||
                    $record['paid_at'] >= $pay['expire_at'] ||
                    ($meta['match_mode'] === 'remark' && $meta['remark'] !== $record['remark'])
                ) {
                    continue;
                }
                $byChannel[$pay['channel_id']][] = $pay;
            }
            // 同账号请求可合并，订单范围不能合并；无通道证据时绝不靠遍历顺序抢单。
            // 已被本批选中的订单也参与归属判断，避免后续流水假装只剩一个通道。
            if (count($byChannel) > 1) {
                $this->logger->record(
                    'receipt.match',
                    ['plugin_code' => $this->getCode(), 'reason_code' => 'ambiguous_channel'],
                    'warning',
                );
                continue;
            }
            $candidates = array_values(
                array_filter(
                    $byChannel[$context['channel_id']] ?? [],
                    static fn(array $pay): bool => !isset($used[$pay['pay_no']]),
                ),
            );
            if ($candidates === []) {
                continue;
            }
            $paid = strtotime($record['paid_at']);
            $pay = $candidates[0];
            // 只需最优候选，不对整组排序；同距时仍按创建时间和完整 ID 决定。
            foreach ($candidates as $candidate) {
                $comparison =
                    abs($prepared[$candidate['pay_no']]['requested_at'] - $paid) <=>
                    abs($prepared[$pay['pay_no']]['requested_at'] - $paid) ?:
                    strcmp($candidate['created_at'], $pay['created_at']) ?:
                    strlen($candidate['id']) <=> strlen($pay['id']) ?:
                    strcmp($candidate['id'], $pay['id']);
                if ($comparison < 0) {
                    $pay = $candidate;
                }
            }
            $meta = $prepared[$pay['pay_no']]['meta'];
            $used[$pay['pay_no']] = true;
            $results[] = [
                'status' => 'success',
                'pay_no' => $pay['pay_no'],
                'paid_amount' => $meta['original_amount'],
                'chan_order_no' => $record['trade_no'] ?? $record['event_key'],
                'chan_trade_no' => $record['trade_no'],
                'pay_type' => $record['pay_type'],
                'received_amount' => $record['amount'],
                'paid_at' => $record['paid_at'] ?? $context['received_at'],
                'event_key' => $record['event_key'],
                'raw' => $record['raw'],
            ];
        }
        return $results;
    }

    public function notifySuccess(): string|Response
    {
        throw new UnsupportedPaymentOperationException();
    }

    public function notifyFail(string $message): string|Response
    {
        throw new UnsupportedPaymentOperationException();
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

    /** @var array<string,true> 本实例已处理的登录账号，防止同一轮正式查单后重复保活。 */
    private array $queriedAccounts = [];

    /** 只把有原单的通道交给账号归并；范围轮换不产生同轮第二次流水查询。 */
    public function collect(array $orders, array $channels, DateTimeImmutable $deadline): iterable
    {
        $orders = array_filter(
            $orders,
            fn(array $pay): bool => $this->receiptMeta($pay['ext_json'] ?? []) !== null,
        );
        $this->queriedAccounts = [];
        $active = array_fill_keys(array_column($orders, 'channel_revision_id'), true);
        $channels = array_intersect_key($channels, $active);
        $store = $this->cache->store('receipt');
        $last = $store->get('shouqianba.rotation');
        $next = $last;
        try {
            $groups = $this->collectionGroups($channels, is_string($last) ? $last : null);
            foreach ($groups as $key => $group) {
                if ($this->clock->now() >= $deadline) {
                    break;
                }
                $result = $this->runAccount($key, $group, $orders, $deadline, false);
                if ($result !== null) {
                    $next = $key;
                    yield $result;
                }
            }
        } finally {
            // 保存本批实际处理到的账号；跳过等待中的账号不会推进游标。
            if ($next !== $last) {
                $store->set('shouqianba.rotation', $next, 0);
            }
        }
    }

    /** 无订单时也可低频探测；正常查询已验证的状态推迟专门保活。 */
    public function prelogin(array $channels, DateTimeImmutable $deadline): iterable
    {
        $store = $this->cache->store('receipt');
        $last = $store->get('shouqianba.rotation');
        $next = $last;
        try {
            $groups = $this->collectionGroups($channels, is_string($last) ? $last : null);
            foreach ($groups as $key => $group) {
                if ($this->clock->now() >= $deadline) {
                    break;
                }
                if (isset($this->queriedAccounts[$key])) {
                    continue;
                }
                $result = $this->runAccount($key, $group, [], $deadline, true);
                if ($result !== null) {
                    $next = $key;
                    yield [
                        'channel_revision_ids' => $result['channel_revision_ids'],
                        'error' => $result['error'],
                    ];
                }
            }
        } finally {
            if ($next !== $last) {
                $store->set('shouqianba.rotation', $next, 0);
            }
        }
    }

    /**
     * 只有本插件解释收钱吧登录、门店和终端字段；相同登录不同门店共用状态并按轮切换。
     * 原单收款范围不变时才使用当前认证参数，避免编辑配置把旧订单切到另一收款账号。
     * 轮转位置复用入口本次读取；凭证候选仅保存摘要到密码的映射，不复制完整配置。
     * @return array<string,array<string,array{channels:array,settings:array,digests:array<string,string>}>> 登录账号 → 查询范围。
     */
    private function collectionGroups(array $channels, ?string $last): array
    {
        $groups = [];
        foreach ($channels as $id => $channel) {
            $settings = $channel['configuration'];
            $current = $channel['current_configuration'];
            $scopeFields = ['watcher_username', 'receipt_account_no', 'receipt_merchant_name'];
            if (is_array($current)) {
                $same =
                    ShouQianBaClient::terminalFilter($current) ===
                    ShouQianBaClient::terminalFilter($settings);
                foreach ($scopeFields as $field) {
                    if (($current[$field] ?? '') !== ($settings[$field] ?? '')) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    $settings['watcher_password'] = $current['watcher_password'];
                }
            }
            $login = hash('sha256', $this->getCode() . ':' . $settings['watcher_username']);
            $scope = ShouQianBaClient::scopeKey($settings);
            $password = $settings['watcher_password'];
            $digest = hash(
                'sha256',
                preg_match('/^[a-fA-F0-9]{32}$/D', $password)
                    ? strtolower($password)
                    : md5($password),
            );
            $groups[$login][$scope]['channels'][(string) $id] = $channel;
            $groups[$login][$scope]['settings'] ??= $settings;
            $groups[$login][$scope]['digests'][$digest] = $password;
        }
        ksort($groups);
        $keys = array_keys($groups);
        $offset = $last === null ? false : array_search($last, $keys, true);
        if ($offset !== false) {
            $keys = [...array_slice($keys, $offset + 1), ...array_slice($keys, 0, $offset + 1)];
            $groups = array_replace(array_fill_keys($keys, []), $groups);
        }
        return $groups;
    }

    /**
     * 一个账号只执行一个范围。状态始终按 expire=0 保存，网络失败不丢失已经取得的 token。
     * 明确认证拒绝清除失效认证，下轮恢复；本轮不重发流水接口。秘密只进入插件缓存。
     */
    private function runAccount(
        string $key,
        array $group,
        array $orders,
        DateTimeImmutable $deadline,
        bool $probe,
    ): ?array {
        $store = $this->cache->store('receipt');
        $state = $store->get('shouqianba.account.' . $key, []);
        if (!is_array($state)) {
            $state = [];
        }
        $persisted = $state;
        $digests = [];
        foreach ($group as $item) {
            $digests += $item['digests'];
        }
        // 明确换了唯一认证参数可立即重试；普通配置变化不会跳过保活间隔或清除登录态。
        if (
            count($digests) === 1 &&
            ($state['credential_digest'] ?? '') !== (string) array_key_first($digests)
        ) {
            unset(
                $state['session'],
                $state['last_verified_at'],
                $state['retry_at'],
                $state['next_query_at'],
            );
        }
        $now = $this->clock->now()->getTimestamp();
        $interval = (int) $this->config->get('receipt.prelogin_interval_seconds', 600);
        $expires = (int) ($state['session']['expires_at'] ?? 0);
        if ((int) ($state['retry_at'] ?? 0) > $now) {
            return null;
        }
        if (
            $probe &&
            (int) ($state['last_verified_at'] ?? 0) + $interval > $now &&
            ($expires === 0 || $expires > $now + 60)
        ) {
            return null;
        }
        if (!$probe && (int) ($state['next_query_at'] ?? 0) > $now) {
            return null;
        }
        ksort($group);
        $scopes = array_values($group);
        $index = (int) ($state['scope_cursor'] ?? 0) % count($scopes);
        $scope = $scopes[$index];
        $settings = $scope['settings'];
        $batch = [
            'channel_revision_ids' => array_map('strval', array_keys($scope['channels'])),
            'records' => [],
            'limit_reached' => false,
            'error' => null,
        ];
        $until = min(
            $deadline,
            $this->clock
                ->now()
                ->modify(
                    '+' .
                        (int) $this->config->get('receipt.account_budget_seconds', 15) .
                        ' seconds',
                ),
        );
        $state['scope_cursor'] = $index + 1;
        $this->queriedAccounts[$key] = true;
        try {
            // 已验证凭证仍在当前配置中时优先复用；不在不同密码之间反复登录。
            $known = $state['credential_digest'] ?? '';
            if (isset($digests[$known])) {
                $credential = $known;
            } elseif (count($digests) === 1) {
                $credential = (string) array_key_first($digests);
            } else {
                throw new ReceiptCollectionException('configuration_conflict', null, [
                    'stage' => '合并账号',
                    'message' =>
                        '同一登录账号配置了不同密码，且没有可复用的已验证凭证，请核对相关通道',
                ]);
            }
            $settings['watcher_password'] = $digests[$credential];
            if ($known !== $credential) {
                unset($state['session'], $state['last_verified_at']);
            }
            $state['credential_digest'] = $credential;
            $session = is_array($state['session'] ?? null) ? $state['session'] : [];
            $state['session'] = $this->client->authenticate($settings, $session, $until);
            // 登录/刷新改变认证时立即保存；复用未变化的 token 无需在查询前重写。
            if (
                ($persisted['session'] ?? null) !== $state['session'] ||
                ($persisted['credential_digest'] ?? null) !== $state['credential_digest']
            ) {
                $store->set('shouqianba.account.' . $key, $state, 0);
                $persisted = $state;
            }
            if ($probe) {
                $from = $now - 600;
                $to = $now;
            } else {
                $selected = array_filter(
                    $orders,
                    static fn(array $order): bool => isset(
                        $scope['channels'][$order['channel_revision_id']],
                    ),
                );
                if ($selected === []) {
                    return null;
                }
                $from = min(
                    array_map(
                        static fn(array $order): int => strtotime($order['request_at']),
                        $selected,
                    ),
                );
                $to = min(
                    $now,
                    max(
                        array_map(
                            static fn(array $order): int => strtotime($order['expire_at']),
                            $selected,
                        ),
                    ),
                );
            }
            $window = ['from' => $from, 'to' => $to, 'deadline' => $until];
            $data = $this->client->query($settings, $window, $state['session'], $probe ? 1 : 200);
            if (!$probe) {
                $batch['records'] = $this->normalizeTransactions(
                    $data,
                    $settings,
                    $state['session'],
                    $window,
                );
                $batch['limit_reached'] = $data['limit_reached'];
            }
            $state['last_verified_at'] = $this->clock->now()->getTimestamp();
            $state['failures'] = 0;
            $state['retry_at'] = 0;
        } catch (ReceiptCollectionException $error) {
            if ($error->reasonCode === 'authentication_expired') {
                unset($state['session'], $state['last_verified_at']);
            }
            if ($error->reasonCode === 'scope_mismatch') {
                unset($state['session']['scope'], $state['session']['scope_selector']);
            }
            $state['failures'] = min(10, (int) ($state['failures'] ?? 0) + 1);
            $delay =
                $error->retryAfterSeconds ??
                (in_array($error->reasonCode, ['configuration_conflict', 'protocol_error'], true)
                    ? $interval
                    : min(300, 30 * 2 ** min(4, $state['failures'] - 1)));
            $state['retry_at'] = $this->clock->now()->getTimestamp() + $delay;
            $batch['error'] =
                ['reason' => $error->reasonCode, 'retry_after' => $delay] + $error->diagnostic;
        } finally {
            $state['next_query_at'] =
                $this->clock->now()->getTimestamp() +
                max(2, (int) ($settings['receipt_watcher_query_interval_seconds'] ?? 3));
            // 最终查询时间、失败等待及范围变化仍逐次持久化，重启不会重置退避。
            if ($state !== $persisted) {
                $store->set('shouqianba.account.' . $key, $state, 0);
            }
        }
        return $batch;
    }

    /** 收钱吧原交易金额为分，按 V2 核对成功状态、商户/终端归属及付款时间。 */
    private function normalizeTransactions(
        array $data,
        array $settings,
        array $state,
        array $window,
    ): array {
        $records = [];
        $scope = $state['scope'];
        $terminalFilter = ShouQianBaClient::terminalFilter($settings);
        $terminal = $terminalFilter['terminal_sn'] ?? ($terminalFilter['device_fingerprint'] ?? '');
        foreach ($data['rows'] as $row) {
            if (
                !is_array($row) ||
                !isset($row['status']) ||
                !in_array((string) $row['status'], ['0', '2000'], true)
            ) {
                continue;
            }
            $type = match ((string) ($row['payway'] ?? '')) {
                '2' => 'alipay',
                '3' => 'wxpay',
                '17' => 'unionpay',
                default => null,
            };
            if ($type === null) {
                continue;
            }
            foreach (['merchant_sn', 'merchant_id', 'store_sn', 'store_id'] as $key) {
                if (!empty($scope[$key]) && (string) ($row[$key] ?? '') !== $scope[$key]) {
                    continue 2;
                }
            }
            $account = $settings['receipt_account_no'];
            $ids = array_map(
                'strval',
                array_intersect_key(
                    $row,
                    array_flip([
                        'merchant_sn',
                        'merchant_id',
                        'store_sn',
                        'store_id',
                        'store_client_sn',
                    ]),
                ),
            );
            if (!in_array($account, $ids, true)) {
                continue;
            }
            $terminals = [
                (string) ($row['terminal_sn'] ?? ''),
                (string) ($row['terminal_device_fingerprint'] ?? ''),
                preg_replace('/^QRCODE:/', '', (string) ($row['operator'] ?? '')),
            ];
            if ($terminal !== '' && !in_array($terminal, $terminals, true)) {
                continue;
            }
            $amount = $row['original_amount'] ?? null;
            if (
                (!is_int($amount) && !is_string($amount)) ||
                !preg_match('/^[1-9][0-9]{0,8}$/D', (string) $amount)
            ) {
                throw new ReceiptCollectionException('protocol_error', null, [
                    'stage' => '解析流水',
                    'message' => '收钱吧流水的 original_amount 金额字段无效',
                ]);
            }
            Money::assertRange((int) $amount);
            if (!is_string($row['order_sn'] ?? null) || $row['order_sn'] === '') {
                throw new ReceiptCollectionException('protocol_error', null, [
                    'stage' => '解析流水',
                    'message' => '收钱吧流水缺少有效的 order_sn 交易号',
                ]);
            }
            $paid = $this->paymentTime(
                $row['finish_time'] ?? ($row['channel_finish_time'] ?? ($row['ctime'] ?? null)),
            );
            if ($paid->getTimestamp() < $window['from'] || $paid->getTimestamp() > $window['to']) {
                continue;
            }
            $remark = $row['reflect'] ?? null;
            if ($remark !== null && !is_string($remark)) {
                throw new ReceiptCollectionException('protocol_error', null, [
                    'stage' => '解析流水',
                    'message' => '收钱吧流水的 reflect 备注字段不是文本',
                ]);
            }
            $remark = preg_match('/(?:^|\D)([0-9]{4})(?:\D|$)/u', $remark ?? '', $code)
                ? $code[1]
                : null;
            $records[] = [
                'pay_type' => $type,
                'event_key' => $row['order_sn'],
                'raw' => $row,
                'trade_no' => $row['order_sn'],
                'amount' => (int) $amount,
                'paid_at' => $paid->format('Y-m-d H:i:s'),
                'remark' => $remark === '' ? null : $remark,
            ];
        }
        return $records;
    }

    /**
     * 保留 V2 的秒/毫秒时间戳及完整日期，不以接收时间填补缺失值。
     * 时间戳对象须转上海时区；日期文本沿用应用时区并严格核对。
     */
    private function paymentTime(mixed $value): DateTimeImmutable
    {
        if (
            (is_int($value) || is_string($value)) &&
            preg_match('/^[1-9][0-9]{8,12}$/D', (string) $value)
        ) {
            $timestamp = (int) $value;
            if ($timestamp > 9999999999) {
                $timestamp = intdiv($timestamp, 1000);
            }
            return (new DateTimeImmutable('@' . $timestamp))->setTimezone(
                new DateTimeZone('Asia/Shanghai'),
            );
        }
        $date = is_string($value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value)
            : false;
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new ReceiptCollectionException('protocol_error', null, [
                'stage' => '解析流水',
                'message' => '收钱吧流水缺少有效的付款时间',
            ]);
        }
        return $date;
    }
}
