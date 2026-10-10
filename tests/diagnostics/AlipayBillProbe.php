<?php
declare(strict_types=1);

namespace tests\diagnostics;

use app\common\sdk\alipay\AlipayBillClient;
use app\common\util\Clock;
use app\exception\ReceiptCollectionException;

/** 显式运行的开发诊断：两次观察完整窗口，只返回计数，不写订单或采集进度。 */
final class AlipayBillProbe
{
    public function __construct(private readonly AlipayBillClient $client, private readonly Clock $clock) {}

    /**
     * 全部查询共用一个截止时间；任何一页失败、跨页重复或两遍不一致都抛错，不能报告部分成功。
     * 账务标识/摘要只在此次内存中比较，不输出可用于关联账号或真实分录的标识、哈希、金额和描述。
     *
     * @param array<string,mixed> $account 原始账号设置仅交给客户端，参见 AlipayBillClient::queryPage 的字段合同。
     * @param int $fromTimestamp 包含的窗口起点，Unix 秒。
     * @param int $toTimestamp 不包含的窗口终点，Unix 秒。
     * @param int $maxPages 每遍最多 1～10 页；每页请求 200 条，最多比较 2000 个分录身份。
     * @param int $budgetSeconds 两遍合计 5～60 秒，已过期不续期，网络错误不在此自动重试。
     * @return array{
     *     status:string,passes:int,pages_per_pass:int,records:int,identical_observations:bool,
     *     counts:array{income:int,expense:int,zero_amount:int,with_trade_no:int,with_bill_source:int,with_type:int,with_memo:int},
     *     platform_acceptance:string,scope_verified:bool,payment_time_verified:bool
     * }
     * @throws ReceiptCollectionException 网络/验签/分页/预算等失败，不输出部分窗口的成功摘要。
     * @throws \InvalidArgumentException 本地参数不符合客户端或本探测预算边界。
     */
    public function inspect(#[\SensitiveParameter] array $account, int $fromTimestamp, int $toTimestamp,
        int $maxPages = 3, int $budgetSeconds = 20): array
    {
        if ($maxPages < 1 || $maxPages > 10 || $budgetSeconds < 5 || $budgetSeconds > 60) {
            throw new \InvalidArgumentException('账务探测页数或时间预算无效');
        }
        $deadline = $this->clock->now()->modify('+' . $budgetSeconds . ' seconds');
        $first = $this->observe($account, $fromTimestamp, $toTimestamp, $maxPages, $deadline);
        $second = $this->observe($account, $fromTimestamp, $toTimestamp, $maxPages, $deadline);
        if ($first['identities'] !== $second['identities']) {
            throw new ReceiptCollectionException('pagination_incomplete');
        }
        return ['status' => 'observed', 'passes' => 2, 'pages_per_pass' => $second['pages'],
            'records' => count($second['identities']), 'identical_observations' => true, 'counts' => $second['counts'],
            'platform_acceptance' => 'not_attested', 'scope_verified' => false, 'payment_time_verified' => false];
    }

    /**
     * 每遍从第一页开始，页总数必须固定，跨页不能复用同一 account_log_id；不以空页或条数不足猜结束。
     * 按身份排序后比较两遍内容，允许平台在同一完整集合内调整顺序，但不隐藏重复、缺失或字段变化。
     *
     * @param array<string,mixed> $account 不保存至输出或数据库。
     * @return array{identities:array<string,string>,pages:int,counts:array{income:int,expense:int,zero_amount:int,with_trade_no:int,with_bill_source:int,with_type:int,with_memo:int}}
     */
    private function observe(#[\SensitiveParameter] array $account, int $from, int $to, int $maxPages, \DateTimeImmutable $deadline): array
    {
        $identities = [];
        $counts = ['income' => 0, 'expense' => 0, 'zero_amount' => 0, 'with_trade_no' => 0,
            'with_bill_source' => 0, 'with_type' => 0, 'with_memo' => 0];
        $total = null;
        for ($number = 1; $number <= $maxPages; $number++) {
            $this->withinDeadline($deadline);
            $page = $this->client->queryPage($account, $from, $to, $number, $deadline);
            $this->withinDeadline($deadline);
            if (($total !== null && $total !== $page->total) || $page->total > $maxPages * $page->pageSize) {
                throw new ReceiptCollectionException('pagination_incomplete');
            }
            $total = $page->total;
            foreach ($page->entries as $entry) {
                $identity = hash('sha256', $entry['account_log_id']);
                if (isset($identities[$identity])) { throw new ReceiptCollectionException('pagination_incomplete'); }
                $identities[$identity] = hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR));
                $counts[$entry['direction'] === '收入' ? 'income' : 'expense']++;
                if ($entry['amount_cents'] === 0) { $counts['zero_amount']++; }
                foreach (['alipay_order_no' => 'with_trade_no', 'bill_source' => 'with_bill_source', 'type' => 'with_type', 'memo' => 'with_memo'] as $field => $counter) {
                    if ($entry[$field] !== null) { $counts[$counter]++; }
                }
            }
            if ($page->nextPage() === null) {
                if (count($identities) !== $total) { throw new ReceiptCollectionException('pagination_incomplete'); }
                ksort($identities, SORT_STRING);
                $this->withinDeadline($deadline);
                return ['identities' => $identities, 'pages' => $number, 'counts' => $counts];
            }
        }
        throw new ReceiptCollectionException('pagination_incomplete');
    }

    private function withinDeadline(\DateTimeImmutable $deadline): void
    {
        if ($this->clock->now() >= $deadline) { throw new ReceiptCollectionException('budget_exhausted'); }
    }
}
