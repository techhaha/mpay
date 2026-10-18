<?php
declare(strict_types=1);

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant as Code;
use app\common\constant\NotifyConstant;
use app\common\constant\PaymentResultCode;
use app\common\constant\RefundConstant;
use app\common\constant\TradeConstant;
use app\common\util\Money;
use app\exception\BusinessException;
use app\model\payment\BizOrder;
use app\model\payment\NotifyTask;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\notify\NotifyAttemptRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\receipt\ReceiptFlowRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayCallbackLogRepository;
use app\repository\payment\trade\PayOrderOperationLogRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\repository\system\AdminOperationLogRepository;
use app\repository\system\AdminUserRepository;
use app\service\payment\notify\NotifyQueryService;
use app\service\payment\receipt\ReceiptFlowQueryService;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * 后台业务单/支付尝试的统一查询与导出口径；只返回明确字段，不透出收银台 token、签名正文、租约或平台配置。
 * 每次列表/详情/历史使用同一读取快照，避免把成功前的业务状态与成功后的通知责任拼接为一条结果。
 */
final class PayOrderQueryService extends BaseService
{
    public function __construct(
        private readonly BizOrderRepository $bizOrders,
        private readonly PayOrderRepository $payOrders,
        private readonly NotifyTaskRepository $tasks,
        private readonly NotifyAttemptRepository $attempts,
        private readonly ReceiptFlowRepository $flows,
        private readonly PayOrderOperationLogRepository $operations,
        private readonly PayCallbackLogRepository $callbacks,
        private readonly PayOrderActionResolverService $actions,
        private readonly AdminUserRepository $admins,
        private readonly AdminOperationLogRepository $audit,
        private readonly NotifyQueryService $notificationQuery,
        private readonly ReceiptFlowQueryService $receiptQuery,
        private readonly RefundOrderRepository $refunds,
        private readonly PaymentCatalogRepository $catalog,
    ) {}

    /**
     * @param array{from:string,until:string,status:?int,notification_status?:?string,biz_no:?string,pay_no:?string,merchant_order_no:?string} $filters 已校验的上海时区左闭右开范围。
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int} 金额输出为定点元字符串。
     */
    public function listing(array $filters, int $page, int $limit): array
    {
        return $this->transaction(
            fn(): array => [
                'items' => $this->rows($this->bizOrders->adminPage($filters, $page, $limit)),
                'total' => $this->bizOrders->adminCount($filters),
                'page' => $page,
                'limit' => $limit,
            ],
        );
    }

    /** @return array{order:array<string,mixed>,notification:?array,receipt:?array,actions:array<string,bool|string|null>} */
    public function detail(string $bizNo): array
    {
        return $this->transaction(function () use ($bizNo): array {
            $biz = $this->business($bizNo);
            $pay =
                $biz['active_pay_no'] === null
                    ? null
                    : $this->payOrders->find($biz['active_pay_no']);
            $task =
                $pay === null
                    ? null
                    : $this->tasks->findByEvent(NotifyConstant::EVENT_PAY_SUCCESS, $pay['pay_no']);
            $receipt =
                $pay === null || $pay['receipt_flow_id'] === null
                    ? null
                    : $this->flows->find($pay['receipt_flow_id']);
            if (
                $pay !== null &&
                $pay['receipt_flow_id'] !== null &&
                ($receipt === null ||
                    $receipt['receipt_scope_key'] !== $pay['receipt_scope_key'] ||
                    $receipt['pay_no'] !== $pay['pay_no'])
            ) {
                throw new RuntimeException('支付关联流水与原范围不一致');
            }
            $receiptView = null;
            if ($receipt !== null) {
                $receiptView = $this->receiptQuery->summary($receipt, $pay) + [
                    'receipt_scope_key' => $receipt['receipt_scope_key'],
                    'remark' => $receipt['remark'],
                ];
            }
            // 只取原单保存的产品/动作标识，不读取活动插件推测，也不公开其他展示参数。
            $presentation = $pay === null ? [] : $pay['ext_json']['presentation'] ?? [];
            return [
                'order' => $this->row(
                    $biz,
                    $pay,
                    $task,
                    $pay === null ? [] : $this->catalog->typeCodes(),
                ) + [
                    'pay_product' => $presentation['pay_product'] ?? null,
                    'pay_action' => $presentation['pay_action'] ?? null,
                ],
                'notification' => $this->notificationQuery->summary($task),
                'refunds' =>
                    $pay === null
                        ? []
                        : array_map(
                            static fn($refund): array => [
                                'refund_no' => $refund['refund_no'],
                                'amount' => Money::format((int) $refund['refund_amount']),
                                'status_label' => RefundConstant::label((int) $refund['status']),
                                'result_code' => $refund['result_code'],
                                'result_label' => PaymentResultCode::label($refund['result_code']),
                                'channel_refund_no' => $refund['channel_refund_no'],
                                'created_at' => $refund['created_at'],
                            ],
                            $this->refunds->forPayment($pay['pay_no']),
                        ),
                'receipt' => $receiptView,
                'actions' => $this->actions->resolve($biz, $pay, $task),
            ];
        });
    }

    /**
     * 独立分页保证全部操作/回调/通知尝试都可访问，不以“最新若干条”冒充完整时间线。
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function history(string $bizNo, string $kind, int $page, int $limit): array
    {
        return $this->transaction(function () use ($bizNo, $kind, $page, $limit): array {
            $biz = $this->business($bizNo);
            $number = $biz['active_pay_no'];
            $records = ['items' => [], 'total' => 0];
            $fields = [];
            if ($kind === 'operations') {
                if ($number !== null) {
                    $records = $this->operations->pageForPay($number, $page, $limit);
                } else {
                    $records = $this->audit->pageForTarget('biz_order', $bizNo, $page, $limit);
                }
                $fields = [
                    'id',
                    'admin_user_id',
                    'request_id',
                    'action',
                    'reason',
                    'before_status',
                    'after_status',
                    'created_at',
                ];
            } elseif ($kind === 'callbacks') {
                if ($number !== null) {
                    $records = $this->callbacks->pageForPay($number, $page, $limit);
                }
                $fields = ['id', 'request_id', 'result_code', 'created_at'];
            } elseif ($kind === 'notifications') {
                $task =
                    $number === null
                        ? null
                        : $this->tasks->findByEvent(NotifyConstant::EVENT_PAY_SUCCESS, $number);
                if ($task !== null) {
                    $records = $this->attempts->pageForNotification(
                        $task['notify_no'],
                        $page,
                        $limit,
                    );
                }
                $fields = [
                    'id',
                    'attempt_no',
                    'started_at',
                    'finished_at',
                    'http_status',
                    'duration_ms',
                    'result_code',
                    'response_body',
                    'response_truncated',
                ];
            } else {
                throw new InvalidArgumentException('未知订单历史类型');
            }
            $items = [];
            foreach ($records['items'] as $record) {
                $item = [];
                // getData 是原始存储数据；逐字段访问才应用模型 ID/JSON 类型，不将大整数 ID 作为 JSON 数字发送。
                foreach ($fields as $field) {
                    if ($record->hasData($field)) {
                        $item[$field] = $record[$field];
                    }
                }
                if ($kind === 'callbacks') {
                    $item['result_label'] = $this->receiptQuery->reasonLabel(
                        $item['result_code'] ?? null,
                    );
                } elseif ($kind === 'notifications') {
                    $item['result_label'] = $this->notificationQuery->reasonLabel(
                        $item['result_code'] ?? null,
                    );
                }
                if ($kind === 'operations') {
                    // 审计证据也逐字段映射，不能把后续新增内部字段自动暴露给浏览器。
                    $proof = $number === null ? $record['summary_json'] : $record['evidence_json'];
                    if ($number === null && is_array($proof)) {
                        foreach (['reason', 'before_status', 'after_status'] as $key) {
                            $item[$key] = $proof[$key] ?? null;
                        }
                    }
                    $item['evidence'] = is_array($proof)
                        ? array_intersect_key(
                            $proof,
                            array_flip([
                                'received_amount',
                                'paid_at',
                                'platform_trade_no',
                                'reference',
                                'flow_id',
                                'close_semantics',
                                'notify_no',
                                'attempt_no',
                                'notify_merchant',
                                'previous_notify_status',
                            ]),
                        )
                        : [];
                    if (isset($item['evidence']['received_amount'])) {
                        $item['evidence']['received_amount'] = Money::format(
                            (int) $item['evidence']['received_amount'],
                        );
                    }
                }
                $items[] = $item;
            }
            return [
                'items' => $items,
                'total' => $records['total'],
                'page' => $page,
                'limit' => $limit,
            ];
        });
    }

    /** 根据已验证的业务号找到唯一原尝试；未创建支付单不能通过后台动作伪造一次支付。 */
    public function paymentNumber(string $bizNo): string
    {
        return $this->business($bizNo)['active_pay_no'] ??
            throw new BusinessException('订单尚未生成支付尝试', Code::CONFLICT);
    }

    /**
     * 同一读取快照按 200 行分批写入临时流，最大 10000 行；生成完毕提交审计后才发送 HTTP，避免导出中混入错误 JSON。
     * 临时流最多在内存保留 1 MiB，之后由 PHP 临时流管理文件；不返回文件路径，响应持有者必须关闭流。
     *
     * @param array{from:string,until:string,status:?int,notification_status?:?string,biz_no:?string,pay_no:?string,merchant_order_no:?string} $filters
     * @return resource 可读 CSV 流；不是文件路径或公开下载链接。
     */
    public function export(array $filters, AdminActionContext $actor): mixed
    {
        $from = new DateTimeImmutable($filters['from']);
        $until = new DateTimeImmutable($filters['until']);
        if ($until <= $from || $until->getTimestamp() - $from->getTimestamp() > 31 * 86400) {
            throw new BusinessException(
                '一次导出最多 31 天，请缩小筛选范围',
                Code::VALIDATION_FAILED,
            );
        }
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('无法准备导出文件');
        }
        try {
            $this->transaction(function () use ($filters, $actor, $stream): void {
                $admin = $this->admins->lock($actor->adminId);
                if (
                    $admin === null ||
                    (int) $admin['status'] !== 1 ||
                    (int) $admin['session_version'] !== $actor->sessionVersion
                ) {
                    throw new BusinessException('管理员会话已失效', Code::UNAUTHENTICATED);
                }
                $total = $this->bizOrders->adminCount($filters);
                if ($total > 10000) {
                    throw new BusinessException(
                        '一次导出最多 10000 条，请缩小筛选范围',
                        Code::VALIDATION_FAILED,
                    );
                }
                if (fwrite($stream, "\xEF\xBB\xBF") !== 3) {
                    throw new RuntimeException('导出写入失败');
                }
                $this->csvRow($stream, [
                    '应用 ID',
                    'PID',
                    '轮询组 ID',
                    '通道 ID',
                    '业务单号',
                    '外部订单号',
                    '支付单号',
                    '订单名称',
                    '状态',
                    '业务金额（元）',
                    '提示付款金额（元）',
                    '已确认实收（元）',
                    '提示偏移（元）',
                    '实收与业务差额（元）',
                    '确认来源',
                    '创建时间',
                    '付款时间',
                    '通知状态',
                    '支付方式编码',
                ]);
                $last = '0';
                $written = 0;
                do {
                    $businesses = $this->bizOrders->exportBatch($filters, $last, 200);
                    foreach ($this->rows($businesses) as $row) {
                        $this->csvRow($stream, [
                            $row['app_id'],
                            $row['pid'],
                            $row['poll_group_id'] ?? '',
                            $row['channel_id'] ?? '',
                            $row['biz_no'],
                            $row['merchant_order_no'],
                            $row['pay_no'] ?? '',
                            $row['subject'],
                            $row['status_label'],
                            $row['order_amount'],
                            $row['receipt_amount'] ?? '',
                            $row['received_amount'] ?? '',
                            $row['offset_amount'] ?? '',
                            $row['received_difference'] ?? '',
                            $row['confirmation_source_label'] ?? '',
                            $row['created_at'],
                            $row['paid_at'] ?? '',
                            $row['notification_status_label'],
                            $row['pay_type'] ?? '',
                        ]);
                        $written++;
                    }
                    if ($businesses !== []) {
                        $last = $businesses[array_key_last($businesses)]['id'];
                    }
                } while (count($businesses) === 200);
                if ($written !== $total) {
                    throw new RuntimeException('导出与查询快照计数不一致');
                }
                $this->audit->record([
                    'admin_user_id' => $actor->adminId,
                    'request_id' => $actor->requestId,
                    'action' => 'orders.export',
                    'target_type' => 'biz_order',
                    'target_id' => null,
                    'result_code' => 'prepared',
                    'client_ip' => null,
                    'summary_json' => json_encode(
                        ['filters' => $filters, 'rows' => $written],
                        JSON_THROW_ON_ERROR,
                    ),
                ]);
            });
            rewind($stream);
            return $stream;
        } catch (Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    /**
     * 文本前导公式字符或控制符加单引号，避免电子表格执行外部输入。
     * @param resource $stream 本服务创建的临时流。
     * @param list<string> $cells 已映射为导出口径的一行。
     */
    private function csvRow(mixed $stream, array $cells): void
    {
        foreach ($cells as &$cell) {
            $cell = (string) $cell;
            if (preg_match('/^[\s\p{Z}\x{FEFF}]*[=+\-@]|^[\x00-\x1f]/u', $cell)) {
                $cell = "'" . $cell;
            }
        }
        unset($cell);
        if (fputcsv($stream, $cells, ',', '"', '') === false) {
            throw new RuntimeException('导出写入失败');
        }
    }

    private function business(string $number): BizOrder
    {
        return $this->bizOrders->find($number) ??
            throw new BusinessException('业务订单不存在', Code::NOT_FOUND);
    }

    /**
     * 固定批量查询组装一页，不把通知次数统计为成交次数。
     * @param list<BizOrder> $businesses
     * @return list<array<string,mixed>>
     */
    private function rows(array $businesses): array
    {
        $pays = $this->payOrders->forBizOrders(
            array_map(static fn(BizOrder $biz): string => $biz['biz_no'], $businesses),
        );
        $tasks = $this->tasks->forPayOrders(
            array_map(static fn(PayOrder $pay): string => $pay['pay_no'], array_values($pays)),
        );
        // 按支付单保存的方式 ID 批量映射，包含停用方式；不从通道当前配置推测，不逐行查询。
        $typeCodes = $pays === [] ? [] : $this->catalog->typeCodes();
        return array_map(
            fn(BizOrder $biz): array => $this->row(
                $biz,
                $pays[$biz['biz_no']] ?? null,
                isset($pays[$biz['biz_no']])
                    ? $tasks[$pays[$biz['biz_no']]['pay_no']] ?? null
                    : null,
                $typeCodes,
            ),
            $businesses,
        );
    }

    /**
     * @param array<int|string,string> $typeCodes 含已停用方式的字典，关联依据为支付单冻结的 pay_type_id。
     * @return array<string,mixed> 未选择方式和未确认实收保留 null；字典缺失时仍保留方式 ID 供区分异常。
     */
    private function row(BizOrder $biz, ?PayOrder $pay, ?NotifyTask $task, array $typeCodes): array
    {
        if (
            ($biz['active_pay_no'] === null) !== ($pay === null) ||
            ($pay !== null &&
                ($pay['pay_no'] !== $biz['active_pay_no'] ||
                    $pay['biz_no'] !== $biz['biz_no'] ||
                    (int) $pay['status'] !== (int) $biz['status'] ||
                    $pay->originalAmount() !== (int) $biz['order_amount']))
        ) {
            throw new RuntimeException('业务单与支付尝试不一致');
        }
        $notice = $this->notificationQuery->summary($task);
        if (
            $task !== null &&
            ($pay === null ||
                $task['pay_no'] !== $pay['pay_no'] ||
                $task['biz_no'] !== $biz['biz_no'])
        ) {
            throw new RuntimeException('原通知责任与业务身份不一致');
        }
        return [
            'app_id' => $biz['app_id'],
            'pid' => $biz['pid'],
            'poll_group_id' => $pay === null ? null : $pay['poll_group_id'],
            'route_mode' => $pay === null ? null : $pay['route_mode'],
            'biz_no' => $biz['biz_no'],
            'merchant_order_no' => $biz['merchant_order_no'],
            'trace_no' => $biz['trace_no'],
            'subject' => $biz['subject'],
            'status' => (int) $biz['status'],
            'status_label' => TradeConstant::label((int) $biz['status']),
            'order_amount' => Money::format((int) $biz['order_amount']),
            'created_at' => $biz['created_at'],
            'paid_at' => $biz['paid_at'],
            'expires_at' => $biz['expire_at'],
            'pay_no' => $pay === null ? null : $pay['pay_no'],
            'pay_type_id' => $pay === null ? null : $pay['pay_type_id'],
            'pay_type' => $pay === null ? null : $typeCodes[$pay['pay_type_id']] ?? null,
            'receipt_amount' => $pay === null ? null : Money::format((int) $pay->receiptAmount()),
            'received_amount' =>
                $pay === null || $pay->receivedAmount() === null
                    ? null
                    : Money::format((int) $pay->receivedAmount()),
            'offset_amount' =>
                $pay === null
                    ? null
                    : Money::format((int) $pay->receiptAmount() - $pay->originalAmount()),
            'received_difference' =>
                $pay === null || $pay->receivedAmount() === null
                    ? null
                    : Money::format((int) $pay->receivedAmount() - (int) $biz['order_amount']),
            'confirmation_source' => $pay === null ? null : $pay['confirmation_source'],
            'confirmation_source_label' => TradeConstant::confirmationLabel(
                $pay === null ? null : $pay['confirmation_source'],
            ),
            'channel_id' => $pay === null ? null : $pay['channel_id'],
            'channel_trade_no' => $pay === null ? null : $pay['channel_trade_no'],
            'receipt_scope_key' => $pay === null ? null : $pay['receipt_scope_key'],
            'notification_first_ack_at' => $notice['first_ack_at'] ?? null,
            'notification_status' => $notice['status'] ?? null,
            'notification_status_label' =>
                $biz['notify_url'] === null
                    ? '未设置通知地址'
                    : $notice['status_label'] ??
                        ((int) $biz['status'] === TradeConstant::ORDER_STATUS_SUCCESS
                            ? '异常：缺少通知记录'
                            : '尚未生成通知'),
        ];
    }
}
