<?php
declare(strict_types=1);

namespace app\common\sdk\alipay;

use app\exception\ReceiptCollectionException;

/**
 * 支付宝账务页的已校验结构；入账分录不是支付成功事件，不提供可信付款时间或备注匹配结论。
 * 客户端须先完成响应原文 RSA2 验签，再调用 decode；本类只核对字段、金额和分页，不自行证明来源身份。
 * entries 仅供内部来源适配使用，含平台交易号/参考描述，不能原样作为后台或易支付的公开响应。
 */
final readonly class AlipayBillPage
{
    /**
     * @param list<array{
     *     account_log_id:string,alipay_order_no:?string,amount_cents:int,direction:string,
     *     booked_at:string,bill_source:?string,type:?string,memo:?string
     * }> $entries 金额为有符号整数分；booked_at 保留平台入账时间原文，不能填作可信 paid_at。
     * @param int $total 该查询条件下的平台分录总数，不是目标二维码收款数。
     */
    private function __construct(public array $entries, public int $page, public int $pageSize, public int $total)
    {
    }

    /**
     * 在验签之后检查原始 JSON 类型，避免生成模型将数字、缺字段或 null 强转为看似有效的字符串。
     * 页码/大小须与原请求一致，明细数量须与总数一致；空结果只允许 total_size=0 的明确合同。
     *
     * @throws ReceiptCollectionException 结构或分页矛盾；不能返回空列表后推进采集游标。
     */
    public static function decode(#[\SensitiveParameter] string $body, int $requestedPage, int $requestedSize): self
    {
        try {
            if (strlen($body) > 4194304) { throw new \UnexpectedValueException(); }
            $value = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if (!$value instanceof \stdClass) { throw new \UnexpectedValueException(); }
            $page = self::integer($value->page_no ?? null, 1, 1000000);
            $size = self::integer($value->page_size ?? null, 1, 2000);
            $total = self::integer($value->total_size ?? null, 0, 2147483647);
            if ($page !== $requestedPage || $size !== $requestedSize) { throw new \UnexpectedValueException(); }
            $rows = $value->detail_list ?? null;
            // 官方模型注明无结果时可省略 detail_list；显式 null/对象不能被当成这个省略合同。
            if (!property_exists($value, 'detail_list') && $total === 0) { $rows = []; }
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > $size) { throw new \UnexpectedValueException(); }
            $expected = max(0, min($size, $total - ($page - 1) * $size));
            if (count($rows) !== $expected || ($rows === [] && $page > 1)) { throw new \UnexpectedValueException(); }
            $entries = [];
            $seen = [];
            foreach ($rows as $row) {
                if (!$row instanceof \stdClass) { throw new \UnexpectedValueException(); }
                $id = self::text($row->account_log_id ?? null, 128);
                if (isset($seen[$id])) { throw new \UnexpectedValueException(); }
                $seen[$id] = true;
                $amount = self::amount($row->trans_amount ?? null);
                $direction = self::text($row->direction ?? null, 16);
                if (!in_array($direction, ['收入', '支出'], true)
) {
                    throw new \UnexpectedValueException();
                }
                $entries[] = ['account_log_id' => $id, 'alipay_order_no' => self::optional($row, 'alipay_order_no', 128),
                    'amount_cents' => $amount, 'direction' => $direction, 'booked_at' => self::text($row->trans_dt ?? null, 64),
                    'bill_source' => self::optional($row, 'bill_source', 128), 'type' => self::optional($row, 'type', 256),
                    'memo' => self::optional($row, 'trans_memo', 2048)];
            }
            return new self($entries, $page, $size, $total);
        } catch (\JsonException|\UnexpectedValueException) {
            throw new ReceiptCollectionException('protocol_error');
        }
    }

    /** 只表明本次平台分页是否还有下一页，不保证不同请求之间的总数/排序稳定。 */
    public function nextPage(): ?int
    {
        return $this->page * $this->pageSize < $this->total ? $this->page + 1 : null;
    }

    private static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string) $value)
            || (int) $value < $minimum || (int) $value > $maximum) { throw new \UnexpectedValueException(); }
        return (int) $value;
    }

    /**
     * 账务页可能包含大额调拨或负数支出，不能套用目标支付单上限后丢弃整页其他事实。
     * 此处只守住原生整数范围；后续确认为目标收款时仍须执行 Money 的单笔约束。
     */
    private static function amount(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]{0,16})(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new \UnexpectedValueException();
        }
        $cents = bcmul($value, '100', 0);
        if (bccomp($cents, (string) PHP_INT_MIN, 0) < 0 || bccomp($cents, (string) PHP_INT_MAX, 0) > 0) {
            throw new \UnexpectedValueException();
        }
        return (int) $cents;
    }

    private static function text(mixed $value, int $maximum): string
    {
        if (!is_string($value) || $value === '' || trim($value) !== $value || strlen($value) > $maximum
            || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \UnexpectedValueException();
        }
        return $value;
    }

    /** 可选描述允许省略/null/空串，但保留原文；不拿参考字段推导账务类型或付款备注。 */
    private static function optional(\stdClass $row, string $field, int $maximum): ?string
    {
        $value = $row->{$field} ?? null;
        if ($value === null || $value === '') { return null; }
        if (!is_string($value) || strlen($value) > $maximum || !mb_check_encoding($value, 'UTF-8')
            || str_contains($value, "\0")) { throw new \UnexpectedValueException(); }
        return $value;
    }
}
