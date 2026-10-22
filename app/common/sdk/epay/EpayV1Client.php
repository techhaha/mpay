<?php
declare(strict_types=1);

namespace app\common\sdk\epay;

use app\common\util\Clock;
use app\common\util\HttpClient;
use app\common\util\HttpTargetPolicy;
use app\exception\BusinessException;
use app\exception\HttpRequestException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use GuzzleHttp\Psr7\Request;
use JsonException;

/** 易支付平台的有界表单传输；不重试请求、不记录密钥或原文，不把传输失败当作确定拒绝。 */
final class EpayV1Client
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly Clock $clock,
    ) {}

    /** 配置允许站点根地址或安装子目录；端点由插件固定，不接受带 query/fragment 的网关。 */
    public static function gateway(string $gateway): string
    {
        try {
            $uri = HttpTargetPolicy::uri($gateway);
        } catch (BusinessException) {
            // 动作内发现配置地址错误时尚未外呼，不能把它记录为平台已受理但结果未知。
            throw new PaymentDefinitiveException('第三方网关地址无效');
        }
        if ($uri->getQuery() !== '' || $uri->getFragment() !== '') {
            throw new PaymentDefinitiveException('第三方网关地址不能包含查询参数或片段');
        }
        return rtrim((string) $uri, '/') . '/';
    }

    /**
     * 必须在事务外调用；返回平台 JSON，业务成功码由插件按具体动作解释。
     *
     * @param string $endpoint 插件固定的相对端点；POST 可带固定操作 query，例如 api.php?act=refund。
     * @param array<string,string> $parameters 明确挑选的协议字段，不能传整个配置。
     * @return array<string,mixed>
     * @throws PaymentUncertainException 请求/响应异常，无法确定上游是否已处理。
     */
    public function request(
        string $gateway,
        string $endpoint,
        array $parameters,
        bool $get = false,
    ): array {
        $gateway = self::gateway($gateway);
        $encoded = http_build_query($parameters, '', '&', PHP_QUERY_RFC1738);
        $url = $gateway . $endpoint . ($get ? '?' . $encoded : '');
        $request = new Request(
            $get ? 'GET' : 'POST',
            $url,
            ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
            $get ? null : $encoded,
        );
        try {
            $response = $this->http->send(
                $request,
                $this->clock->now()->modify('+15 seconds'),
            );
        } catch (HttpRequestException $error) {
            // 不串接可能含凭证的原异常或响应片段。
            throw new PaymentUncertainException('第三方平台请求未完成：' . $error->reasonCode);
        }
        if ($response->getStatusCode() !== 200) {
            throw new PaymentUncertainException('第三方平台返回 HTTP ' . $response->getStatusCode());
        }
        try {
            $data = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentUncertainException('第三方平台返回了无效 JSON');
        }
        if (
            !is_array($data) ||
            array_is_list($data) ||
            !isset($data['code']) ||
            !(is_int($data['code']) || (is_string($data['code']) && preg_match('/^-?[0-9]{1,9}$/D', $data['code'])))
        ) {
            throw new PaymentUncertainException('第三方平台响应缺少有效结果码');
        }
        $data['code'] = (int) $data['code'];
        return $data;
    }
}
