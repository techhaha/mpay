<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\interface\ReceiptCollectorInterface;
use app\common\sdk\captcha\TtshituClient;
use app\common\util\Clock;
use app\common\util\HttpClient;
use app\common\util\SafeLogger;
use app\exception\BusinessException;
use app\exception\HttpRequestException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\exception\ReceiptCollectionException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\system\config\SystemConfigService;
use DateTimeImmutable;
use mpay\premium\shared\LicenseException;
use mpay\premium\xingyifu\Client;
use RuntimeException;
use think\Cache;
use think\Config;
use think\facade\Db;
use think\Request;
use think\Response;

/** 星驿付驿汇客动态收款；平台匹配留在插件，付款请求不临时登录。 */
final class XingyifuYhkReceiptPayment extends BasePayment implements ReceiptCollectorInterface
{
    private const PRODUCT = 'scan';
    private const ACTION = 'addRenPage';
    private const NEEDS_CAPTCHA = true;

    protected array $paymentInfo = [
        'code' => 'xingyifu_yhk_receipt', 'name' => '星驿付驿汇客', 'version' => '1.3.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'], 'prelogin' => true,
        'description' => "使用本插件前，请将网站与监听进程切换至 PHP 8.4+，并安装、启用 mpay_premium.so 扩展。\n本插件登录需要图鉴验证码识别，请先在「系统设置 → 验证码识别」中配置图鉴账号和密码。\n使用本插件前，请在网站与监听所用的 PHP 中安装并启用 GMP 扩展。国密 SDK 由 V1 基础版提供，无需执行 Composer。\nUbuntu/Debian：宝塔安装 GMP 失败且提示缺少 GMP 库或头文件时，到宝塔左侧「终端」执行 sudo apt-get install libgmp-dev，再回 PHP 扩展页面安装 GMP，并重启 PHP 与监听进程。",
        'config_schema' => [
            ['type' => 'input', 'field' => 'license_code', 'title' => '授权码', 'value' => '', 'secret' => true,
                'help' => '付费插件，需要填写插件授权码', 'validate' => ['string', 'max' => 8192]],
            ['type' => 'input', 'field' => 'watcher_username', 'title' => '平台登录账号', 'validate' => ['require', 'string', 'max' => 128]],
            ['type' => 'input', 'field' => 'watcher_password', 'title' => '平台登录密码', 'secret' => true, 'validate' => ['require', 'string', 'max' => 256]],
            ['type' => 'number', 'field' => 'receipt_valid_seconds', 'title' => '收款有效期（秒）', 'value' => 300, 'validate' => ['require', 'between' => [60, 1800]]],
            ['type' => 'number', 'field' => 'receipt_watcher_query_interval_seconds', 'title' => '账号查询间隔（秒）', 'value' => 3, 'validate' => ['require', 'between' => [2, 300]]],
        ],
    ];

    /** @var array<string,bool> 本轮已请求账号，防止正式查单后重复预登录探测。 */
    private array $attempted = [];

    /** 元信息读取不创建 SDK；开发源码与发布扩展使用相同类名。 */
    private ?Client $client = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly TtshituClient $captcha,
        private readonly Cache $cache,
        private readonly PayOrderRepository $orders,
        private readonly BizOrderRepository $business,
        private readonly PaymentChannelRepository $channels,
        private readonly SystemConfigService $settings,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly SafeLogger $logger,
    ) {
    }

    public function init(array $config): void
    {
        parent::init($config);
        $this->sdk();
    }

    /** pay、采集和预登录共用环境检查；商业授权只由 SDK 新建收款单时验证。 */
    private function sdk(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if (PHP_VERSION_ID < 80400) {
            throw new BusinessException('本插件需要 PHP 8.4 或更高版本', ApiResponseConstant::SERVICE_UNAVAILABLE);
        }
        foreach (['curl', 'openssl', 'gmp'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new BusinessException('本插件需要启用 PHP ' . $extension . ' 扩展', ApiResponseConstant::SERVICE_UNAVAILABLE);
            }
        }
        if (!class_exists(Client::class)) {
            throw new BusinessException('请安装与当前 PHP 环境匹配的 MPAY Premium 扩展', ApiResponseConstant::SERVICE_UNAVAILABLE);
        }
        if (!defined(Client::class . '::API_VERSION') || Client::API_VERSION !== 3
            || (extension_loaded('typephp_mpay_premium')
                && version_compare((string) phpversion('typephp_mpay_premium'), '1.2.0', '<'))) {
            throw new BusinessException('MPAY Premium SDK 版本不兼容，请更新 SDK', ApiResponseConstant::SERVICE_UNAVAILABLE);
        }
        return $this->client = new Client($this->http, $this->captcha);
    }

    public function isPreloginEnabled(): bool
    {
        return $this->paymentInfo['prelogin'];
    }

    public function pay(array $order): array
    {
        $previous = $order['extra']['dynamic_receipt'] ?? null;
        if (is_array($previous) && ($previous['phase'] ?? '') === 'ready') {
            return $this->presentation($order, $previous);
        }
        if (is_array($previous) && ($previous['phase'] ?? '') === 'creating' && empty($previous['platform_id'])) {
            throw new PaymentUncertainException('原收款单创建结果尚未确定，不能重复创建');
        }
        try {
            $session = $this->paymentSession();
        } catch (PaymentDefinitiveException $error) {
            // 已有平台创建事实的原单不能因当前凭证不可用被改判为确定失败。
            if (is_array($previous) && ($previous['phase'] ?? '') === 'creating') {
                throw new PaymentUncertainException($error->getMessage());
            }
            throw $error;
        }
        $meta = $this->prepare($order);
        if ($meta['phase'] === 'ready') {
            return $this->presentation($order, $meta);
        }
        $usedSession = $session;
        $deadline = $this->clock->now()->modify('+15 seconds');
        try {
            $created = $this->sdk()->create(
                $this->configuration,
                $session,
                $order,
                $meta,
                $deadline,
                function (array $progress) use ($order, &$meta): void {
                    $this->persist($order, $progress);
                    // 后续失败必须保留最近已保存的页面和阶段，不能写回调用前的旧快照。
                    $meta = $progress;
                },
            );
        } catch (LicenseException $error) {
            $meta['phase'] = 'rejected';
            $this->persist($order, $meta);
            throw new PaymentDefinitiveException($error->getMessage(), $error);
        } catch (ReceiptCollectionException $error) {
            if ($error->reasonCode === 'authentication_expired') {
                $this->requestPrelogin($usedSession);
            }
            $message = $error->diagnostic['message'] ?: '平台创建未完成';
            if (empty($meta['platform_id']) && in_array($error->reasonCode, ['authentication_expired', 'scope_mismatch'], true)) {
                $meta['phase'] = 'rejected';
                $this->persist($order, $meta);
                throw new PaymentDefinitiveException($message);
            }
            throw new PaymentUncertainException($message);
        } catch (HttpRequestException) {
            throw new PaymentUncertainException('平台建单网络中断或超时，结果未知');
        }
        $meta = array_replace($meta, $created, ['phase' => 'ready']);
        $this->persist($order, $meta);
        return $this->presentation($order, $meta);
    }

    /** 创建事实仅写本单扩展；锁顺序沿用 Biz → Pay → Channel，事务中没有 HTTP。 */
    private function prepare(array $order): array
    {
        return Db::transaction(function () use ($order): array {
            $this->business->lock($order['biz_no']);
            $pay = $this->orders->lock($order['pay_no']);
            $this->channels->lock($order['channel_id']);
            $this->assertExecution($pay, $order);
            $ext = $pay['ext_json'] ?? [];
            if (isset($ext['dynamic_receipt'])) {
                $meta = $ext['dynamic_receipt'];
                if (($meta['phase'] ?? '') === 'rejected') {
                    throw new PaymentDefinitiveException('原收款单已被平台拒绝');
                }
                if (($meta['phase'] ?? '') !== 'ready' && empty($meta['platform_id'])) {
                    throw new PaymentUncertainException('原收款单创建结果尚未确定');
                }
                return $meta;
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            $expires = min($pay['expire_at'], $this->clock->now()->modify('+' . $this->getConfig('receipt_valid_seconds', 300) . ' seconds')->format('Y-m-d H:i:s'));
            $meta = ['plugin_code' => $this->getCode(), 'phase' => 'creating', 'amount' => $order['amount'],
                'title' => substr($order['pay_no'], 1), 'effective_at' => $now, 'expire_at' => $expires, 'platform_id' => null];
            $ext['dynamic_receipt'] = $meta;
            $this->orders->saveReceiptContext($order['pay_no'], $order['amount'], $ext, $expires, $now);
            $this->business->shortenExpiry($order['biz_no'], $expires);
            return $meta;
        });
    }

    private function persist(array $order, array $meta): void
    {
        Db::transaction(function () use ($order, $meta): void {
            $this->business->lock($order['biz_no']);
            $pay = $this->orders->lock($order['pay_no']);
            $this->channels->lock($order['channel_id']);
            $this->assertExecution($pay, $order);
            $ext = $pay['ext_json'] ?? [];
            $ext['dynamic_receipt'] = $meta;
            $this->orders->saveReceiptContext(
                $order['pay_no'],
                (int) $pay['pay_amount'],
                $ext,
                $pay['expire_at'],
                $this->clock->now()->format('Y-m-d H:i:s')
            );
        });
    }

    private function assertExecution(PayOrder $pay, array $order): void
    {
        $execution = $pay['ext_json']['payment_context']['execution'] ?? [];
        $expected = $order['extra']['payment_context']['execution']['token'] ?? null;
        if ($pay['biz_no'] !== $order['biz_no'] || $pay['channel_id'] !== $order['channel_id'] || $pay['plugin_code'] !== $this->getCode()
            || (int) $pay['status'] !== TradeConstant::ORDER_STATUS_PAYING || !is_string($expected)
            || ($execution['phase'] ?? '') !== 'claimed' || ($execution['token'] ?? '') !== $expected
            || ($execution['lease_until'] ?? '') <= $this->clock->now()->format('Y-m-d H:i:s')) {
            throw new PaymentUncertainException('原付款执行已失去执行权，保留创建事实待核对');
        }
    }

    private function presentation(array $order, array $meta): array
    {
        if (($meta['plugin_code'] ?? '') !== $this->getCode() || ($meta['amount'] ?? null) !== $order['amount']
            || !is_string($meta['qrcode'] ?? null) || strlen($meta['qrcode']) > 2331
            || !in_array(parse_url($meta['qrcode'], PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new PaymentUncertainException('原收款单创建事实或二维码无效');
        }
        return ['status' => 'pending', 'pay_no' => $order['pay_no'], 'paid_amount' => null,
            'chan_order_no' => $meta['platform_id'], 'chan_trade_no' => null, 'pay_type' => $order['pay_type_code'],
            'pay_product' => self::PRODUCT, 'pay_action' => self::ACTION,
            'presentation' => ['pay_page' => 'page', 'pay_params' => [
                '_page' => 'dynamicReceipt', 'payment_url' => $meta['qrcode']]]];
    }

    public function query(array $order): array
    {
        return ['status' => 'pending', 'pay_no' => $order['pay_no'], 'paid_amount' => null,
            'chan_order_no' => $order['chan_order_no'] ?? null, 'chan_trade_no' => $order['chan_trade_no'] ?? null];
    }

    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }
    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }
    public function notifySuccess(): string|Response
    {
        throw new UnsupportedPaymentOperationException();
    }
    public function notifyFail(string $message): string|Response
    {
        throw new UnsupportedPaymentOperationException();
    }

    /** 只匹配本批已就绪订单；完整候选仍参与跨通道歧义检查。 */
    public function notify(Request|array $payload, array $context): array
    {
        if ($payload instanceof Request) {
            throw new UnsupportedPaymentOperationException();
        }
        $results = [];
        $used = [];
        $entries = $payload['records'];
        usort($entries, static fn (array $a, array $b): int => strcmp($a['record']['paid_at'] ?? '', $b['record']['paid_at'] ?? '')
            ?: strcmp($a['record']['event_key'], $b['record']['event_key']));
        foreach ($entries as $entry) {
            if ($entry['consumed_pay_no'] !== null) {
                continue;
            }
            $record = $entry['record'];
            $candidates = [];
            foreach ($payload['orders'] as $pay) {
                $meta = $pay['ext_json']['dynamic_receipt'] ?? [];
                if (($meta['plugin_code'] ?? '') !== $this->getCode() || ($meta['phase'] ?? '') !== 'ready'
                    || $pay['pay_type'] !== $record['pay_type'] || $pay['pay_amount'] !== $record['amount']
                    || $record['paid_at'] === null || $record['paid_at'] < $meta['effective_at'] || $record['paid_at'] >= $pay['expire_at']
                    || !$this->matches($meta, $record)) {
                    continue;
                }
                $candidates[] = $pay;
            }
            if (count($candidates) > 1) {
                $this->logger->record('receipt.match', ['plugin_code' => $this->getCode(), 'reason_code' => 'ambiguous_channel'], 'warning');
            }
            if (count($candidates) !== 1) {
                continue;
            }
            $pay = $candidates[0];
            if ($pay['channel_id'] !== $context['channel_id'] || isset($used[$pay['pay_no']])) {
                continue;
            }
            $used[$pay['pay_no']] = true;
            $results[] = ['status' => 'success', 'pay_no' => $pay['pay_no'], 'paid_amount' => $pay['pay_amount'],
                'chan_order_no' => $pay['ext_json']['dynamic_receipt']['platform_id'],
                'chan_trade_no' => $record['trade_no'], 'pay_type' => $record['pay_type'],
                'received_amount' => $record['amount'],
                'paid_at' => $record['paid_at'] ?? $context['received_at'],
                'event_key' => $record['event_key'],
                'raw' => $record['raw']];
        }
        return $results;
    }

    private function matches(array $meta, array $record): bool
    {
        $extra = $record['extra'] ?? [];
        return ($extra['title'] ?? '') === $meta['title'] && ($extra['dept_id'] ?? '') === ($meta['dept_id'] ?? null)
            && ($extra['platform_id'] ?? '') === $meta['platform_id'];
    }

    public function collect(array $orders, array $channels, DateTimeImmutable $deadline): iterable
    {
        $this->attempted = [];
        yield from $this->run($orders, $channels, $deadline, false);
    }

    public function prelogin(array $channels, DateTimeImmutable $deadline): iterable
    {
        yield from $this->run([], $channels, $deadline, true);
    }

    /** 账号内合并认证；优先提示只跳过正常间隔，失败退避和原轮询预算保持。 */
    private function run(array $orders, array $channels, DateTimeImmutable $deadline, bool $probe): iterable
    {
        $active = array_fill_keys(array_column(array_filter($orders, static fn (array $pay): bool =>
            ($pay['ext_json']['dynamic_receipt']['phase'] ?? '') === 'ready'), 'channel_revision_id'), true);
        $groups = [];
        foreach ($channels as $id => $channel) {
            $settings = $channel['configuration'];
            $current = $channel['current_configuration'];
            if (is_array($current) && ($current['watcher_username'] ?? '') === ($settings['watcher_username'] ?? '')
                && $this->sdk()->scope($current) === $this->sdk()->scope($settings)) {
                $settings['watcher_password'] = $current['watcher_password'];
            }
            $account = $this->account($settings);
            $scope = $this->sdk()->scope($settings);
            $groups[$account][$scope]['configuration'] ??= $settings;
            $groups[$account][$scope]['ids'][] = (string) $id;
            $groups[$account][$scope]['credentials'][$this->credential($settings)] = true;
            $groups[$account][$scope]['active'] = ($groups[$account][$scope]['active'] ?? false) || isset($active[$id]);
        }
        ksort($groups);
        $rotation = $this->accountState('@rotation');
        $keys = array_map('strval', array_keys($groups));
        $offset = array_search($rotation['last'] ?? null, $keys, true);
        if ($offset !== false) {
            $keys = [...array_slice($keys, $offset + 1), ...array_slice($keys, 0, $offset + 1)];
        }
        $requests = [];
        foreach ($keys as $account) {
            $requests[$account] = $this->preloginRequest($account);
        }
        // 账号提示不承载订单任务，原有轮转继续决定同优先级账号的顺序。
        $keys = [
            ...array_values(array_filter($keys, static fn (string $account): bool => $requests[$account] !== [])),
            ...array_values(array_filter($keys, static fn (string $account): bool => $requests[$account] === [])),
        ];
        foreach ($keys as $account) {
            if ($this->clock->now() >= $deadline || ($probe && isset($this->attempted[$account]))) {
                continue;
            }
            $scopes = $groups[$account];
            ksort($scopes);
            $credentials = [];
            foreach ($scopes as $scope) {
                $credentials += $scope['credentials'];
            }
            $state = $this->accountState($account);
            $request = $requests[$account];
            $wanted = $scopes[$request['scope'] ?? ''] ?? null;
            $requested = $wanted !== null && $request['credential'] === $this->credential($wanted['configuration']);
            $force = $requested && (!$this->sessionReady($state, $request['credential'], $request['scope'])
                || $this->rejectedSession($state, $request));
            if ($request !== [] && !$force) {
                // 配置已变化或当前凭证已就绪；确认旧提示即可，不能再次清空或登录。
                $state['prelogin_handled'] = $request['id'];
                $this->saveAccountState($account, $state);
                $request = [];
            }
            $available = $probe ? $scopes : array_filter($scopes, static fn (array $scope): bool => $scope['active']);
            $list = array_values($available);
            if (!$force && $list === []) {
                continue;
            }
            $selected = $force ? $wanted : $list[(int) ($state['cursor'] ?? 0) % count($list)];
            $settings = $selected['configuration'];
            $ids = $selected['ids'];
            $credential = $this->credential($settings);
            if (($state['credential'] ?? '') !== $credential) {
                $state['session'] = [];
                $state['verified_at'] = 0;
                $state['queried_at'] = 0;
                unset($state['retry_at']);
            }
            if ((int) ($state['session']['expires_at'] ?? 0) > 0 && (int) $state['session']['expires_at'] <= time()) {
                $state['session'] = [];
                $state['verified_at'] = 0;
            }
            if ((int) ($state['retry_at'] ?? 0) > time()) {
                continue;
            }
            $eligible = array_filter($orders, static fn (array $pay): bool => in_array($pay['channel_revision_id'], $ids, true)
                && ($pay['ext_json']['dynamic_receipt']['phase'] ?? '') === 'ready');
            if (!$probe && $eligible === []) {
                if ($force) {
                    // 无原单的恢复只能由已有 prelogin 入口报告；提前结束 collect，给它保留本轮预算。
                    return;
                }
                continue;
            }
            $interval = $probe ? (int) $this->config->get('receipt.prelogin_interval_seconds', 600)
                : max(2, (int) ($settings['receipt_watcher_query_interval_seconds'] ?? 3));
            $last = $probe ? ($state['verified_at'] ?? 0) : ($state['queried_at'] ?? 0);
            if (!$force && $last + $interval > time()) {
                continue;
            }
            $this->attempted[$account] = true;
            $state['cursor'] = ((int) ($state['cursor'] ?? 0) + 1) % count($list);
            $state['credential'] = $credential;
            $state['session'] ??= [];
            if ($request !== []) {
                // 只确认本次读取的编号；并发写入的不同提示仍留待后续轮次处理。
                $state['prelogin_handled'] = $request['id'];
                if ($this->rejectedSession($state, $request)) {
                    $state['session'] = [];
                    $state['verified_at'] = 0;
                }
            }
            $error = null;
            $data = ['records' => [], 'limit_reached' => false];
            try {
                if (count($credentials) !== 1) {
                    throw new ReceiptCollectionException('configuration_conflict', 600, ['stage' => '账号配置', 'message' => '同一平台账号存在不同密码，请核对通道配置']);
                }
                $until = min(
                    $deadline,
                    $this->clock->now()->modify('+' . (int) $this->config->get('receipt.account_budget_seconds', 15) . ' seconds'),
                );
                $captcha = self::NEEDS_CAPTCHA ? $this->settings->values('captcha') : [];
                if (($state['session']['scope'] ?? '') !== $this->sdk()->scope($settings)) {
                    $state['verified_at'] = 0;
                }
                $this->sdk()->authenticate($settings, $state['session'], $until, $captcha);
                $end = $probe ? $this->clock->now()->format('Y-m-d H:i:s') : min($this->clock->now()->format('Y-m-d H:i:s'), max(array_column($eligible, 'expire_at')));
                $start = $probe ? $this->clock->now()->modify('-10 minutes')->format('Y-m-d H:i:s') : min(array_column($eligible, 'request_at'));
                $response = $this->sdk()->query($state['session'], $start, $end, $probe ? 1 : 200, $until);
                // 预登录只验证真实查询成功，不用无关流水判断登录态。
                $data = [
                    'records' => $probe ? [] : $this->normalizeTransactions($response['rows'], $state['session'], $eligible),
                    'limit_reached' => $response['limit_reached'],
                ];
                $state['verified_at'] = time();
                $state['queried_at'] = time();
                unset($state['retry_at']);
            } catch (ReceiptCollectionException $exception) {
                if ($exception->reasonCode === 'authentication_expired') {
                    $state['session'] = [];
                    $state['verified_at'] = 0;
                } elseif ($exception->reasonCode === 'scope_mismatch') {
                    // 范围拒绝保留平台凭证，后续重新选择范围，不盲目重新登录。
                    unset($state['session']['scope']);
                    $state['verified_at'] = 0;
                }
                $wait = $exception->retryAfterSeconds ?? 60;
                $error = ['reason' => $exception->reasonCode, 'retry_after' => $wait] + $exception->diagnostic;
                $state['retry_at'] = time() + $wait;
            } catch (HttpRequestException $exception) {
                $exhausted = $exception->reasonCode === 'deadline_elapsed';
                $error = ['reason' => $exhausted ? 'budget_exhausted' : 'network_error', 'retry_after' => 60,
                    'source' => 'system', 'stage' => '平台请求',
                    'message' => $exhausted ? '本轮采集账号预算已耗尽' : '平台网络请求未完成'];
                $state['retry_at'] = time() + 60;
            } finally {
                $this->saveAccountState($account, $state);
                $this->saveAccountState('@rotation', ['last' => $account]);
            }
            $batch = ['channel_revision_ids' => $ids, 'error' => $error];
            yield $probe ? $batch : $batch + ($error === null ? $data : ['records' => [], 'limit_reached' => false]);
        }
    }

    private function account(array $settings): string
    {
        return $settings['watcher_username'];
    }
    private function credential(array $settings): string
    {
        return hash('sha256', $settings['watcher_username'] . "\0" . $settings['watcher_password']);
    }

    /** 从本地候选投影平台标识，平台字段解析交编译 SDK；最终订单匹配仍在 notify。 */
    private function normalizeTransactions(array $rows, array $session, array $orders): array
    {
        $pages = [];
        foreach ($orders as $order) {
            $meta = $order['ext_json']['dynamic_receipt'];
            $pages[$meta['title']][$meta['platform_id']] = true;
        }
        return $this->sdk()->normalizeTransactions($rows, $session, $pages);
    }

    private function accountState(string $account): array
    {
        $value = $this->cache->store('receipt')->get($this->accountCacheKey($account), []);
        return is_array($value) ? $value : [];
    }

    /** 只有监听保存认证和退避；未变化时不重复写入，写失败明确上抛。 */
    private function saveAccountState(string $account, array $value): void
    {
        if ($this->accountState($account) !== $value
            && !$this->cache->store('receipt')->set($this->accountCacheKey($account), $value, 0)) {
            throw new RuntimeException('无法保存插件账号状态');
        }
    }

    private function sessionReady(array $state, string $credential, string $scope): bool
    {
        $session = $state['session'] ?? [];
        return ($state['credential'] ?? '') === $credential && ($session['scope'] ?? '') === $scope
            && (int) ($state['verified_at'] ?? 0) > 0 && $session !== []
            && ((int) ($session['expires_at'] ?? 0) === 0 || (int) $session['expires_at'] > time());
    }

    /** 只忽略已处理的提示编号，不删除并发产生的新提示。 */
    private function preloginRequest(string $account, ?array $state = null): array
    {
        $request = $this->cache->store('receipt')->get($this->accountCacheKey($account) . '.prelogin', []);
        if (!is_array($request) || $request === []) {
            return [];
        }
        $state ??= $this->accountState($account);
        return ($state['prelogin_handled'] ?? null) === $request['id'] ? [] : $request;
    }

    /** 旧认证拒绝仅否定实际使用的快照，不能清掉监听已更新的状态。 */
    private function rejectedSession(array $state, array $request): bool
    {
        return ($request['rejected_session'] ?? null) !== null
            && ($state['credential'] ?? '') === $request['credential']
            && hash_equals($request['rejected_session'], $this->sessionFingerprint($state['session'] ?? []));
    }

    /** 付款使用驿汇客子令牌；主平台状态及子令牌到期元数据不代表新认证。 */
    private function sessionFingerprint(array $session): string
    {
        return hash('sha256', $session['yhk_token'] ?? '');
    }

    /** 本插件私有缓存，不约束其他插件的状态字段。 */
    private function accountCacheKey(string $account): string
    {
        return 'dynamic_receipt.' . $this->getCode() . '.' . hash('sha256', $account);
    }

    /** 付款只使用本插件已验证的缓存；恢复交给原监听，不在请求中登录。 */
    private function paymentSession(): array
    {
        $account = $this->account($this->configuration);
        $credential = $this->credential($this->configuration);
        $scope = $this->sdk()->scope($this->configuration);
        $state = $this->accountState($account);
        $session = $state['session'] ?? [];
        $request = $this->preloginRequest($account, $state);
        if (!$this->sessionReady($state, $credential, $scope) || $this->rejectedSession($state, $request)) {
            $expired = ($state['credential'] ?? '') === $credential
                && (int) ($session['expires_at'] ?? 0) > 0 && (int) $session['expires_at'] <= time();
            $this->requestPrelogin($expired ? $session : null);
            throw new PaymentDefinitiveException('平台登录凭证尚未就绪，已提示监听恢复，请稍后重新发起支付');
        }
        return $session;
    }

    /** 按账号合并恢复提示，只存摘要；付款不覆盖认证，也不重置失败退避。 */
    private function requestPrelogin(?array $rejectedSession = null): void
    {
        $account = $this->account($this->configuration);
        $credential = $this->credential($this->configuration);
        $scope = $this->sdk()->scope($this->configuration);
        $store = $this->cache->store('receipt');
        $key = $this->accountCacheKey($account) . '.prelogin';
        $previous = $this->preloginRequest($account);
        $request = [
            'credential' => $credential,
            'scope' => $scope,
            'rejected_session' => $rejectedSession === null ? null : $this->sessionFingerprint($rejectedSession),
        ];
        // 请求另一个收款范围，不能抹掉同一账号已经报告的认证失效。
        if (($previous['credential'] ?? '') === $credential && $request['rejected_session'] === null) {
            $request['rejected_session'] = $previous['rejected_session'];
        }
        if ($previous !== [] && array_diff_key($previous, ['id' => true]) === $request) {
            return;
        }
        if (!$store->set($key, ['id' => bin2hex(random_bytes(8))] + $request, 0)) {
            throw new RuntimeException('无法保存插件账号恢复提示');
        }
    }
}
