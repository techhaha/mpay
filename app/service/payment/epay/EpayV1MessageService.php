<?php
declare(strict_types=1);

namespace app\service\payment\epay;

use app\common\constant\ApiResponseConstant;
use app\common\constant\TradeConstant;
use app\common\util\HttpTargetPolicy;
use app\common\util\Money;
use app\common\util\UrlEncodedForm;
use app\common\validation\PaymentTypeCode;
use app\exception\BusinessException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use LogicException;
use RuntimeException;

/** 易支付公开消息白名单及目标 query 合并，通知和浏览器回跳共用规则，不依赖当前 HTTP 请求。 */
final class EpayV1MessageService
{
    private const SUCCESS_FIELDS = [
        'pid',
        'trade_no',
        'out_trade_no',
        'type',
        'name',
        'money',
        'trade_status',
        'param',
        'sign',
        'sign_type',
    ];

    public function __construct(private readonly EpayV1Signer $signer) {}

    /**
     * 只有已确认且相互关联的本地成功才能生成 TRADE_SUCCESS，浏览器输入不能代替此条件。
     *
     * @return array<string,string> 尚未附加目标 query 或签名的固定业务参数。
     */
    public function successParameters(BizOrder $biz, PayOrder $pay, string $pid): array
    {
        if (
            (int) $biz['status'] !== TradeConstant::ORDER_STATUS_SUCCESS ||
            (int) $pay['status'] !== TradeConstant::ORDER_STATUS_SUCCESS ||
            $pay['biz_no'] !== $biz['biz_no'] ||
            $biz['active_pay_no'] !== $pay['pay_no']
        ) {
            throw new LogicException('未确认业务成功，不能生成易支付成功消息');
        }
        $type = $pay['ext_json']['payment_context']['pay_type'] ?? null;
        $param = $biz['ext_json']['param'] ?? '';
        if (!PaymentTypeCode::valid($type) || !is_string($param)) {
            throw new RuntimeException('原始协议上下文缺失');
        }
        $parameters = [
            'pid' => $pid,
            'trade_no' => $pay['pay_no'],
            'out_trade_no' => $biz['merchant_order_no'],
            'type' => $type,
            'name' => $biz['subject'],
            'money' => Money::format((int) $biz['order_amount']),
            'trade_status' => 'TRADE_SUCCESS',
        ];
        if ($param !== '') {
            $parameters['param'] = $param;
        }
        return $parameters;
    }

    /**
     * 固定协议字段由已确认订单覆盖，其他目标 query 原样参与签名，避免拼两次问号或漏签已有字段。
     * 这里只做格式检查与确定性编码；新订单的域名限制在建单处理，通知连接的网络检查在 HTTP 层处理。
     *
     * @param array<string,string> $parameters successParameters 返回的固定参数。
     * @return array<string|int,string> 完整冻结参数，可重复编码为同一请求。
     */
    public function signForTarget(string $target, array $parameters, string $key): array
    {
        $query = UrlEncodedForm::parseQuery(HttpTargetPolicy::uri($target)->getQuery());
        foreach (self::SUCCESS_FIELDS as $field) {
            unset($query[$field]);
        }
        $query = array_replace($query, $parameters);
        unset($query['sign'], $query['sign_type']);
        $query['sign'] = $this->signer->sign($query, $key);
        $query['sign_type'] = 'MD5';
        return $query;
    }

    /**
     * query 已合并进签名参数；保留原 URI 的其他部分，不再解析随后会被替换的旧 query。
     * 本入口核验实际输出的 query 与完整长度，认领端不重复检查；HTTP 层仍独立核验网络目标。
     * @param array<string|int,string> $signedParameters 已合并目标 query 的完整冻结参数。
     */
    public function targetUrl(string $target, array $signedParameters): string
    {
        $uri = HttpTargetPolicy::uri($target);
        $query = http_build_query($signedParameters, '', '&', PHP_QUERY_RFC3986);
        UrlEncodedForm::parseQuery($query, 256);
        $url = (string) $uri->withQuery($query);
        // 原 URI 已核验，原生编码和 withQuery 只替换参数；这里只补核验变化后的总长度。
        if (mb_strlen($url, 'UTF-8') > HttpTargetPolicy::MAX_REQUEST_URL_LENGTH) {
            throw new BusinessException(
                '完整目标 URL 超过允许长度',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        return $url;
    }

    /**
     * @return array{code:int,msg:string,trade_no:string,out_trade_no:string,api_trade_no:string,type:string,pid:string,
     *     addtime:string,endtime:string,name:string,money:string,status:int,param:string,buyer:string}
     */
    public function order(BizOrder $biz, ?PayOrder $pay, string $pid): array
    {
        if (
            ($pay !== null &&
                ($pay['biz_no'] !== $biz['biz_no'] ||
                    $biz['active_pay_no'] !== $pay['pay_no'] ||
                    (int) $pay['status'] !== (int) $biz['status'] ||
                    $pay->originalAmount() !== (int) $biz['order_amount'])) ||
            ($pay === null &&
                ($biz['active_pay_no'] !== null ||
                    (int) $biz['status'] === TradeConstant::ORDER_STATUS_SUCCESS))
        ) {
            throw new RuntimeException('查询的业务单与支付单不一致');
        }
        $type = $pay === null ? '' : $pay['ext_json']['payment_context']['pay_type'] ?? null;
        $param = $biz['ext_json']['param'] ?? '';
        if (
            !is_string($param) ||
            ($pay !== null && !PaymentTypeCode::valid($type))
        ) {
            throw new RuntimeException('查询的原始协议上下文无效');
        }
        return [
            'code' => 1,
            'msg' => '查询成功',
            'trade_no' => $pay === null ? '' : $pay['pay_no'],
            'out_trade_no' => $biz['merchant_order_no'],
            'api_trade_no' => $pay === null ? '' : $pay['channel_trade_no'] ?? '',
            'type' => $type,
            'pid' => $pid,
            'addtime' => $biz['created_at'],
            'endtime' => $biz['paid_at'] ?? '',
            'name' => $biz['subject'],
            'money' => Money::format((int) $biz['order_amount']),
            'status' => (int) $biz['status'] === TradeConstant::ORDER_STATUS_SUCCESS ? 1 : 0,
            'param' => $param,
            'buyer' => '',
        ];
    }
}
