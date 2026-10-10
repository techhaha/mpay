<?php
declare(strict_types=1);

namespace app\common\sdk\alipay;

use app\common\util\Clock;
use app\common\util\HttpClient;
use app\common\util\PlatformTargetPolicy;
use app\exception\HttpRequestException;
use app\exception\ReceiptCollectionException;
use GuzzleHttp\Psr7\Request;

/**
 * 本地支付宝账单 SDK：仅封装 gateway.do 的 alipay.data.bill.accountlog.query。
 * 请求/响应签名交给 AlipaySigner，HTTP 交给现有 Guzzle 边界；不下单、不退款、不匹配本系统订单。
 */
final class AlipayBillClient
{
    private const ORIGIN = 'https://openapi.alipay.com';
    private const PAGE_SIZE = 200;
    private const MAX_RESPONSE_BYTES = 4194304;

    public function __construct(private readonly HttpClient $http, private readonly Clock $clock, private readonly AlipaySigner $signer) {}

    /**
     * 查询一页账务，所有页共享同一个绝对截止；完整翻页与完成游标由采集服务负责。
     * @param array{app_id:string,private_key:string,alipay_public_key:string,bill_user_id:string,app_auth_token?:string} $account
     * @param int $fromTimestamp 左闭右开窗口起点，Unix 秒。
     * @param int $toTimestamp 窗口终点，SDK 请求上海时区时间，插件再按原窗口过滤边界记录。
     * @throws ReceiptCollectionException 网络、验签或分页失败，不以空页替代异常。
     */
    public function queryPage(#[\SensitiveParameter] array $account, int $fromTimestamp, int $toTimestamp,
        int $page, \DateTimeImmutable $deadline): AlipayBillPage
    {
        $now = $this->clock->now();
        if ($fromTimestamp < 1 || $toTimestamp <= $fromTimestamp || $toTimestamp - $fromTimestamp > 31 * 86400
            || $toTimestamp > $now->getTimestamp() || $page < 1 || $page > 1000000) {
            throw new \InvalidArgumentException('账单查询窗口或页码无效');
        }
        if ($deadline <= $now) { throw new ReceiptCollectionException('budget_exhausted'); }
        if ($fromTimestamp < $now->modify('-1 year')->getTimestamp()) { throw new ReceiptCollectionException('history_gap'); }
        if (array_diff(array_keys($account), ['app_id', 'bill_user_id', 'private_key', 'alipay_public_key', 'app_auth_token'])) {
            throw new \InvalidArgumentException('账单配置包含未支持字段');
        }
        foreach (['app_id', 'bill_user_id'] as $field) {
            if (!is_string($account[$field] ?? null) || !preg_match('/^[1-9][0-9]{15,31}$/D', $account[$field])) { throw new \InvalidArgumentException('支付宝应用或账单主体标识无效'); }
        }
        foreach (['private_key', 'alipay_public_key'] as $field) {
            if (!is_string($account[$field] ?? null)) { throw new \InvalidArgumentException('支付宝密钥配置无效'); }
        }
        $zone = new \DateTimeZone('Asia/Shanghai');
        $format = static fn (int $time): string => (new \DateTimeImmutable('@' . $time))->setTimezone($zone)->format('Y-m-d H:i:s');
        $biz = ['bill_user_id' => $account['bill_user_id'], 'start_time' => $format($fromTimestamp),
            'end_time' => $format($toTimestamp), 'page_no' => (string) $page, 'page_size' => (string) self::PAGE_SIZE];
        $parameters = ['app_id' => $account['app_id'], 'method' => 'alipay.data.bill.accountlog.query', 'format' => 'JSON',
            'charset' => 'UTF-8', 'sign_type' => 'RSA2', 'timestamp' => $now->setTimezone($zone)->format('Y-m-d H:i:s'),
            'version' => '1.0', 'biz_content' => json_encode($biz, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        if (isset($account['app_auth_token'])) {
            if (!is_string($account['app_auth_token']) || $account['app_auth_token'] === '' || strlen($account['app_auth_token']) > 4096
                || preg_match('/[\x00-\x20\x7f]/', $account['app_auth_token'])) { throw new \InvalidArgumentException('支付宝授权凭证无效'); }
            $parameters['app_auth_token'] = $account['app_auth_token'];
        }
        $parameters['sign'] = $this->signer->sign($parameters, $account['private_key']);
        try {
            $response = $this->http->send(new Request('POST', self::ORIGIN . '/gateway.do',
                ['Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'],
                http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)), PlatformTargetPolicy::production([self::ORIGIN]), $deadline, self::MAX_RESPONSE_BYTES);
        } catch (HttpRequestException $error) {
            throw new ReceiptCollectionException($error->reasonCode === 'deadline_elapsed' ? 'budget_exhausted' : 'network_error');
        }
        if ($response->getStatusCode() === 429) { throw new ReceiptCollectionException('rate_limited'); }
        if ($response->getStatusCode() !== 200) { throw new ReceiptCollectionException('network_error'); }
        $body = $this->signer->response((string) $response->getBody(), $account['alipay_public_key']);
        $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (($result['code'] ?? null) !== '10000') {
            // 不把平台完整错误报文带入异常；只有成功且已验签的账务正文才进入分页解释。
            throw new ReceiptCollectionException('protocol_error');
        }
        return AlipayBillPage::decode($body, $page, self::PAGE_SIZE);
    }

}
