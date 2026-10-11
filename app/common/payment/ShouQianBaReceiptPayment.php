<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\interface\ReceiptCollectorInterface;
use app\common\sdk\shouqianba\ShouQianBaClient;
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
use SensitiveParameter;
use think\facade\Db;
use think\Request;
use think\Response;

/** 收钱吧码牌插件：SDK 处理登录及交易页，本类解释成功状态、原交易金额、方式、终端和备注。 */
final class ShouQianBaReceiptPayment implements ReceiptCollectorInterface
{
    /** @var array<string,mixed> 随代码交付的元信息和配置字段列表，不含实际账号秘密。 */
    private array $paymentInfo = [
        'code' => 'shouqianba_receipt', 'name' => '收钱吧码牌收款', 'version' => '1.0.0', 'pay_types' => ['alipay', 'wxpay'],
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
            ['type' => 'input', 'field' => 'watcher_username', 'title' => '商户后台账号', 'validate' => [0 => 'require', 1 => 'string', 'length' => [1, 128]]],
            ['type' => 'input', 'field' => 'watcher_password', 'title' => '商户后台密码', 'validate' => [0 => 'require', 1 => 'string', 'length' => [1, 256]], 'secret' => true],
            ['type' => 'input', 'field' => 'receipt_account_no', 'title' => '收款商户或门店编号', 'validate' => [0 => 'require', 1 => 'string', 'length' => [1, 128]]],
            ['type' => 'input', 'field' => 'receipt_merchant_name', 'title' => '码牌商户名称', 'validate' => [0 => 'string', 'length' => [0, 128]], 'value' => ''],
            ['type' => 'input', 'field' => 'receipt_terminal_no', 'title' => '收款终端号', 'validate' => [0 => 'string', 'length' => [0, 128]], 'value' => ''],
        ],
    ];

    public function __construct(private readonly PayOrderRepository $orders, private readonly BizOrderRepository $businessOrders, private readonly ReceiptScopeRepository $scopes,
        private readonly ReceiptMatchReservationRepository $reservations, private readonly Clock $clock, private readonly ShouQianBaClient $client)
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

    /** 登录身份不含密码；查询范围包含门店/终端，流水范围不因切换登录账号而改变。 */
    public function getCollectionIdentity(): array
    {
        $prefix = $this->getCode();
        $login = trim((string) $this->getConfig('watcher_username'));
        $account = trim((string) $this->getConfig('receipt_account_no'));
        $password = trim((string) $this->getConfig('watcher_password'));
        $password = preg_match('/^[a-fA-F0-9]{32}$/D', $password) ? strtolower($password) : md5($password);
        return ['account_key' => hash('sha256', $prefix . ':receipt:' . $account),
            'session_key' => hash('sha256', $prefix . ':login:' . $login),
            'query_key' => hash('sha256', json_encode([$prefix, $login, $account,
                $this->getConfig('receipt_merchant_name', ''), $this->getConfig('receipt_terminal_no', '')], JSON_THROW_ON_ERROR)),
            'auth_fingerprint' => hash('sha256', $prefix . ':' . $password)];
    }

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
     * 按付款时间与 request_at 的距离选择最近候选；不套用手机通知的单候选限制。
     * 相同距离保留仓储的 created_at/id 顺序，重复流水优先返回原订单。
     *
     * @param array{amount:int,paid_at:?string,trade_no:?string,remark:?string,pay_type:string,event_key:string} $record
     */
    private function locateReceipt(array $record, ?string $scopeKey = null, array $excluded = []): PayOrder
    {
        $scope = $scopeKey ?? (string) $this->getConfig('receipt_scope_key');
        if ($scope === '') { throw new LogicException('服务未注入收款范围'); }
        if ($record['trade_no'] !== null) {
            $known = $this->orders->successfulForTrade($scope, $record['trade_no']);
            if ($known !== null) { return $this->assertCollectionOrder($known); }
        }
        // 没有平台交易号时，稳定事件键仍可定位已经确认的原订单。
        $prepared = $this->orders->successfulForEvent($scope, $record['event_key']);
        if ($prepared !== null) { return $this->assertCollectionOrder($prepared); }
        // 使用每单准备时保存的模式；当前通道后续修改不能重新解释已展示的金额或备注。
        $candidates = $this->orders->receiptCandidates($scope, $record['amount'], $record['remark'], $record['paid_at'],
            $this->clock->now()->format('Y-m-d H:i:s'));
        $candidates = array_values(array_filter($candidates, fn (PayOrder $pay): bool => !isset($excluded[$pay['pay_no']]) && $this->collectionAllows($pay)));
        if ($candidates === []) {
            throw new BusinessException('收款消息未定位到支付单', ApiResponseConstant::CONFLICT);
        }
        if ($record['paid_at'] !== null) {
            $paid = strtotime($record['paid_at']);
            usort($candidates, static fn (PayOrder $left, PayOrder $right): int =>
                abs(strtotime($left['request_at'] ?? $left['created_at']) - $paid)
                <=> abs(strtotime($right['request_at'] ?? $right['created_at']) - $paid));
        }
        return $candidates[0];
    }

    /** 共享流水范围仍只选本次完整查询覆盖的配置，避免门店或终端过滤互相串单。 */
    private function collectionAllows(PayOrder $pay): bool
    {
        $ids = $this->getConfig('collection_configuration_ids');
        return $ids === null || in_array($pay->channelRevision['api_config_id'], $ids, true);
    }

    private function assertCollectionOrder(PayOrder $pay): PayOrder
    {
        if (!$this->collectionAllows($pay)) { throw new BusinessException('流水已由其他查询范围确认', ApiResponseConstant::CONFLICT); }
        return $pay;
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

    /** 本插件只接受 CLI 采集流水，未提供平台 HTTP 推送合同。 */
    public function notify(Request $request): array { throw new UnsupportedPaymentOperationException(); }
    public function notifySuccess(): string|Response { return 'success'; }
    public function notifyFail(): string|Response { return 'fail'; }

    /** 成功状态 0/2000、original_amount 为分、payway 2/3 的映射均沿用 V2；费用和净额不代替交易金额。 */
    public function collectPage(array $window, ?array $pageToken, #[SensitiveParameter] ?array $session): array
    {
        $page = $pageToken['page'] ?? 1;
        if (!is_int($page) || $page < 1) { throw new ReceiptCollectionException('pagination_incomplete'); }
        $data = $this->client->queryPage($this->channelConfig, $window, $page, $session);
        if ($pageToken !== null && ($pageToken['total'] ?? null) !== $data['total']) { throw new ReceiptCollectionException('pagination_incomplete'); }
        $records = [];
        $scope = $data['session']['scope'];
        foreach ($data['rows'] as $row) {
            if (!is_array($row) || !isset($row['status']) || !in_array((string) $row['status'], ['0', '2000'], true)) { continue; }
            $type = match ((string) ($row['payway'] ?? '')) { '2' => 'alipay', '3' => 'wxpay', default => null };
            if ($type === null) { continue; }
            foreach (['merchant_sn', 'merchant_id', 'store_sn', 'store_id'] as $key) {
                if (!empty($scope[$key]) && (string) ($row[$key] ?? '') !== $scope[$key]) { continue 2; }
            }
            $account = $this->getConfig('receipt_account_no');
            $ids = array_map('strval', array_intersect_key($row, array_flip(['merchant_sn', 'merchant_id', 'store_sn', 'store_id', 'store_client_sn'])));
            if (!in_array($account, $ids, true)) { continue; }
            $terminal = (string) $this->getConfig('receipt_terminal_no', '');
            $terminals = [(string) ($row['terminal_sn'] ?? ''), (string) ($row['terminal_device_fingerprint'] ?? ''),
                preg_replace('/^QRCODE:/', '', (string) ($row['operator'] ?? ''))];
            if ($terminal !== '' && !in_array($terminal, $terminals, true)) { continue; }
            $amount = $row['original_amount'] ?? null;
            if ((!is_int($amount) && !is_string($amount)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string) $amount)) {
                throw new ReceiptCollectionException('protocol_error');
            }
            Money::assertRange((int) $amount);
            if (!is_string($row['order_sn'] ?? null) || $row['order_sn'] === '') { throw new ReceiptCollectionException('protocol_error'); }
            $paid = $this->paymentTime($row['finish_time'] ?? $row['channel_finish_time'] ?? $row['ctime'] ?? null);
            if ($paid->getTimestamp() < $window['from'] || $paid->getTimestamp() >= $window['to']) { continue; }
            $remark = $row['reflect'] ?? null;
            if ($remark !== null && !is_string($remark)) { throw new ReceiptCollectionException('protocol_error'); }
            $remark = preg_match('/(?:^|\D)([0-9]{4})(?:\D|$)/u', $remark ?? '', $code) ? $code[1] : null;
            $records[] = ['pay_type' => $type, 'event_key' => $row['order_sn'], 'trade_no' => $row['order_sn'], 'amount' => (int) $amount,
                'paid_at' => $paid->format('Y-m-d H:i:s'), 'remark' => $remark === '' ? null : $remark];
        }
        $complete = $page * 200 >= $data['total'];
        return ['records' => $records, 'complete' => $complete, 'next_page' => $complete ? null : ['page' => $page + 1, 'total' => $data['total']],
            'session' => $data['session']];
    }

    /** 秒/毫秒时间戳及明确日期文本为 V2 已登记的接口表示；不以本地接收时间填补缺失值。 */
    private function paymentTime(mixed $value): DateTimeImmutable
    {
        $zone = new DateTimeZone('Asia/Shanghai');
        if ((is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{8,12}$/D', (string) $value)) {
            $timestamp = (int) $value;
            if ($timestamp > 9999999999) { $timestamp = intdiv($timestamp, 1000); }
            return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
        }
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone) : false;
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) { throw new ReceiptCollectionException('protocol_error'); }
        return $date;
    }
}
