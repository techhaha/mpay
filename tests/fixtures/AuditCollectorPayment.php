<?php
declare(strict_types=1);

use app\common\interface\ReceiptCollectorInterface;
use app\common\payment\WechatReceiptPayment;
use app\exception\ReceiptCollectionException;
use think\Request;
use think\Response;

/** 隔离测试中的新采集插件：无需修改调度器即可提供两页合成流水，绝不访问平台。 */
final class AuditCollectorPayment implements ReceiptCollectorInterface
{
    public static bool $failSecondPage = false;
    public static int $pages = 0;
    public static array $batches = [];

    public function __construct(private readonly WechatReceiptPayment $receipt) {}
    public function init(array $channelConfig): void { $this->receipt->init($channelConfig); }
    public function getCode(): string { return 'audit_payment'; }
    public function getName(): string { return '隔离链路插件'; }
    public function getVersion(): string { return '2.0.0'; }
    public function getEnabledPayTypes(): array { return ['wxpay']; }
    public function getDescription(): string { return ''; }
    public function getConfigSchema(): array { return $this->receipt->getConfigSchema(); }
    public function canRetryPay(): bool { return true; }
    public function pay(array $order): array { return $this->receipt->pay($order); }
    public function query(array $order): array { return $this->receipt->query($order); }
    public function close(array $order): array { return $this->receipt->close($order); }
    public function refund(array $order): array { return $this->receipt->refund($order); }
    public function notify(Request $request): array { return $this->receipt->notify($request); }
    public function notifySuccess(): string|Response { return 'success'; }
    public function notifyFail(): string|Response { return 'fail'; }

    public function getCollectionIdentity(): array
    {
        return ['account_key'=>hash('sha256', 'audit_payment:account'), 'query_key'=>hash('sha256', 'audit_payment:query'),
            'session_key'=>null, 'auth_fingerprint'=>hash('sha256', 'audit_payment:authentication')];
    }

    public function collectPage(array $window, ?array $pageToken, ?array $session): array
    {
        if (think\facade\Db::connect()->connect()->inTransaction()) { throw new RuntimeException('采集不应持有业务事务'); }
        self::$pages++;
        if ($pageToken !== null && self::$failSecondPage) { throw new ReceiptCollectionException('network_error'); }
        $records = [];
        foreach ($pageToken === null ? [1,2] : [3] as $id) {
            $records[] = ['pay_type'=>'wxpay', 'event_key'=>'audit-collector-'.$id, 'trade_no'=>'audit-collector-'.$id,
                'amount'=>100, 'paid_at'=>date('Y-m-d H:i:s', $window['from'] + 1), 'remark'=>null];
        }
        return ['records'=>$records, 'complete'=>$pageToken !== null, 'next_page'=>$pageToken === null ? ['page'=>2] : null, 'session'=>null];
    }

    public function matchReceipts(array $receipts): array
    {
        self::$batches[] = count($receipts);
        // 没有对应订单的合成流水；只观察整批投递，不伪造已匹配结果。
        return [];
    }
}
