<?php
declare(strict_types=1);

namespace app\common\sdk\alipay;

use app\common\util\Clock;
use app\common\util\HttpClient;
use app\exception\HttpRequestException;
use app\exception\ReceiptCollectionException;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

/**
 * 本地支付宝账单 SDK：仅封装 gateway.do 的 alipay.data.bill.accountlog.query。
 * 请求/响应签名交给 AlipaySigner，HTTP 交给现有 Guzzle 边界；不下单、不退款、不匹配本系统订单。
 */
final class AlipayBillClient
{
    private const ORIGIN = 'https://openapi.alipay.com';
    private const PAGE_SIZE = 200;
    private const MAX_RESPONSE_BYTES = 4194304;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly AlipaySigner $signer,
    ) {}

    /**
     * 带时间查询第一页最多 200 条；响应先验签再归一化，不翻页或补齐历史。
     * @param array{app_id:string,private_key:string,alipay_public_key:string,bill_user_id:string,app_auth_token?:string} $account
     * @param int $fromTimestamp 左闭右开窗口起点，Unix 秒。
     * @param int $toTimestamp 窗口终点，SDK 请求上海时区时间，插件再按原窗口过滤边界记录。
     * @return array{entries:list<array{alipay_order_no:?string,amount_cents:int,direction:string,booked_at:string,memo:?string}>,limit_reached:bool}
     * @throws ReceiptCollectionException 网络、验签或响应结构失败，不以空列表替代异常。
     */
    public function query(
        array $account,
        int $fromTimestamp,
        int $toTimestamp,
        DateTimeImmutable $deadline,
    ): array {
        $now = $this->clock->now();
        if (
            $fromTimestamp < 1 ||
            $toTimestamp < $fromTimestamp ||
            $toTimestamp - $fromTimestamp > 31 * 86400 ||
            $toTimestamp > $now->getTimestamp()
        ) {
            throw new ReceiptCollectionException('protocol_error', null, [
                'stage' => '查询准备',
                'message' => '支付宝账单查询时间范围无效',
            ]);
        }
        if ($deadline <= $now) {
            throw new ReceiptCollectionException('budget_exhausted', null, [
                'source' => 'system',
                'stage' => '查询准备',
                'message' => '本轮采集预算已用完，尚未请求支付宝',
            ]);
        }
        if ($fromTimestamp < $now->modify('-1 year')->getTimestamp()) {
            throw new ReceiptCollectionException('history_gap', null, [
                'stage' => '查询准备',
                'message' => '订单时间超出支付宝账单查询支持的一年范围',
            ]);
        }
        if (
            array_diff(array_keys($account), [
                'app_id',
                'bill_user_id',
                'private_key',
                'alipay_public_key',
                'app_auth_token',
            ])
        ) {
            throw new InvalidArgumentException('账单配置包含未支持字段');
        }
        foreach (['app_id', 'bill_user_id'] as $field) {
            if (
                !is_string($account[$field] ?? null) ||
                !preg_match('/^[1-9][0-9]{15,31}$/D', $account[$field])
            ) {
                throw new ReceiptCollectionException('configuration_conflict', null, [
                    'stage' => '检查账号配置',
                    'message' => '支付宝应用或账单主体标识应为 16～32 位数字',
                ]);
            }
        }
        foreach (['private_key', 'alipay_public_key'] as $field) {
            if (!is_string($account[$field] ?? null)) {
                throw new ReceiptCollectionException('configuration_conflict', null, [
                    'stage' => '检查账号配置',
                    'message' => '支付宝密钥配置必须是文本',
                ]);
            }
        }
        // Unix 秒按应用上海时区输出；签名时间复用本次查询的业务时钟。
        $biz = [
            'bill_user_id' => $account['bill_user_id'],
            'start_time' => date('Y-m-d H:i:s', $fromTimestamp),
            'end_time' => date('Y-m-d H:i:s', $toTimestamp + 1),
            'page_no' => '1',
            'page_size' => (string) self::PAGE_SIZE,
        ];
        $parameters = [
            'app_id' => $account['app_id'],
            'method' => 'alipay.data.bill.accountlog.query',
            'format' => 'JSON',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'timestamp' => $now->format('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode(
                $biz,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        ];
        if (isset($account['app_auth_token'])) {
            if (
                !is_string($account['app_auth_token']) ||
                $account['app_auth_token'] === '' ||
                strlen($account['app_auth_token']) > 4096 ||
                preg_match('/[\x00-\x20\x7f]/', $account['app_auth_token'])
            ) {
                throw new ReceiptCollectionException('configuration_conflict', null, [
                    'stage' => '检查账号配置',
                    'message' => '支付宝授权凭证格式无效',
                ]);
            }
            $parameters['app_auth_token'] = $account['app_auth_token'];
        }
        $parameters['sign'] = $this->signer->sign($parameters, $account['private_key']);
        try {
            $response = $this->http->send(
                new Request(
                    'POST',
                    self::ORIGIN . '/gateway.do',
                    ['Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'],
                    http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
                ),
                $deadline,
                self::MAX_RESPONSE_BYTES,
            );
        } catch (HttpRequestException $error) {
            throw new ReceiptCollectionException(
                $error->reasonCode === 'deadline_elapsed' ? 'budget_exhausted' : 'network_error',
                null,
                [
                    'source' => 'system',
                    'stage' => '查询流水',
                    'message' => '平台请求未完成（' . $error->reasonCode . '）',
                ],
            );
        }
        if ($response->getStatusCode() !== 200) {
            throw new ReceiptCollectionException(
                $response->getStatusCode() === 429 ? 'rate_limited' : 'network_error',
                null,
                [
                    'source' => 'platform',
                    'stage' => '查询流水',
                    'http_status' => $response->getStatusCode(),
                    'message' => '平台返回 HTTP ' . $response->getStatusCode(),
                ],
            );
        }
        $body = $this->signer->response(
            (string) $response->getBody(),
            $account['alipay_public_key'],
        );
        return self::decode(
            $body,
            array_filter([
                $account['private_key'],
                $account['alipay_public_key'],
                $account['app_auth_token'] ?? '',
            ]),
        );
    }

    /**
     * 在验签之后检查原始 JSON 类型，避免生成模型将数字、缺字段或 null 强转为看似有效的字符串。
     * 页码/大小须与原请求一致，明细数量核对首批上限；空结果只允许 total_size=0 的明确合同。
     *
     * @throws ReceiptCollectionException 结构矛盾；不能把失败伪装成查询成功。
     */
    private static function decode(string $body, array $secrets): array
    {
        try {
            if (strlen($body) > 4194304) {
                throw new UnexpectedValueException();
            }
            $value = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            // 先验证平台错误结构；拒绝信息只取业务码和说明，不保留响应正文。
            if (!($value instanceof stdClass) || !is_string($value->code ?? null)) {
                throw new UnexpectedValueException();
            }
            if ($value->code !== '10000') {
                $messages = [];
                foreach (['msg', 'sub_msg'] as $field) {
                    if (is_string($value->{$field} ?? null) && $value->{$field} !== '') {
                        $messages[] = $value->{$field};
                    }
                }
                throw new ReceiptCollectionException('protocol_error', null, [
                    'source' => 'platform',
                    'stage' => '查询流水',
                    'platform_code' =>
                        $value->code .
                        (is_string($value->sub_code ?? null) ? ' / ' . $value->sub_code : ''),
                    'message' => str_replace($secrets, '[已隐藏]', implode('；', $messages)),
                    'http_status' => 200,
                ]);
            }
            $page = self::integer($value->page_no ?? null, 1, 1000000);
            $size = self::integer($value->page_size ?? null, 1, 2000);
            $total = self::integer($value->total_size ?? null, 0, 2147483647);
            if ($page !== 1 || $size !== self::PAGE_SIZE) {
                throw new UnexpectedValueException();
            }
            $rows = $value->detail_list ?? null;
            // 官方模型注明无结果时可省略 detail_list；显式 null/对象不能被当成这个省略合同。
            if (!property_exists($value, 'detail_list') && $total === 0) {
                $rows = [];
            }
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > $size) {
                throw new UnexpectedValueException();
            }
            if (count($rows) !== min($size, $total)) {
                throw new UnexpectedValueException();
            }
            $entries = [];
            $seen = [];
            foreach ($rows as $row) {
                if (!($row instanceof stdClass)) {
                    throw new UnexpectedValueException();
                }
                $id = self::text($row->account_log_id ?? null, 128);
                if (isset($seen[$id])) {
                    throw new UnexpectedValueException();
                }
                $seen[$id] = true;
                $amount = self::amount($row->trans_amount ?? null);
                $direction = self::text($row->direction ?? null, 16);
                if (!in_array($direction, ['收入', '支出'], true)) {
                    throw new UnexpectedValueException();
                }
                $entries[] = [
                    'alipay_order_no' => self::optional($row, 'alipay_order_no', 128),
                    'raw' => json_decode(
                        json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        true,
                        32,
                        JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
                    ),
                    'amount_cents' => $amount,
                    'direction' => $direction,
                    'booked_at' => self::text($row->trans_dt ?? null, 64),
                    'memo' => self::optional($row, 'trans_memo', 2048),
                ];
            }
            return [
                'entries' => $entries,
                'limit_reached' => $total > $size || count($rows) === $size,
            ];
        } catch (JsonException | UnexpectedValueException) {
            throw new ReceiptCollectionException('protocol_error', null, [
                'stage' => '解析流水',
                'message' => '支付宝账单响应的分页或明细字段不符合接口约定',
                'http_status' => 200,
            ]);
        }
    }

    private static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (
            (!is_string($value) && !is_int($value)) ||
            !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string) $value) ||
            (int) $value < $minimum ||
            (int) $value > $maximum
        ) {
            throw new UnexpectedValueException();
        }
        return (int) $value;
    }

    /**
     * 账务页可能包含大额调拨或负数支出，不能套用目标支付单上限后丢弃整页其他事实。
     * 此处只守住原生整数范围；后续确认为目标收款时仍须执行 Money 的单笔约束。
     */
    private static function amount(mixed $value): int
    {
        if (
            !is_string($value) ||
            !preg_match('/^-?(?:0|[1-9][0-9]{0,16})(?:\.[0-9]{1,2})?$/D', $value)
        ) {
            throw new UnexpectedValueException();
        }
        $cents = bcmul($value, '100', 0);
        if (
            bccomp($cents, (string) PHP_INT_MIN, 0) < 0 ||
            bccomp($cents, (string) PHP_INT_MAX, 0) > 0
        ) {
            throw new UnexpectedValueException();
        }
        return (int) $cents;
    }

    private static function text(mixed $value, int $maximum): string
    {
        if (
            !is_string($value) ||
            $value === '' ||
            trim($value) !== $value ||
            strlen($value) > $maximum ||
            !mb_check_encoding($value, 'UTF-8') ||
            preg_match('/[\x00-\x1f\x7f]/', $value)
        ) {
            throw new UnexpectedValueException();
        }
        return $value;
    }

    /** 可选描述允许省略/null/空串，但保留原文；不拿参考字段推导账务类型或付款备注。 */
    private static function optional(stdClass $row, string $field, int $maximum): ?string
    {
        $value = $row->{$field} ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (
            !is_string($value) ||
            strlen($value) > $maximum ||
            !mb_check_encoding($value, 'UTF-8') ||
            str_contains($value, "\0")
        ) {
            throw new UnexpectedValueException();
        }
        return $value;
    }
}
