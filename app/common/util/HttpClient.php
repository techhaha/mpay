<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\exception\HttpRequestException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use think\Config;

/**
 * Guzzle 的应用出站边界：商户通知与平台 SDK 共用传输，分别采用各自目标授权。
 * HTTP 消息、流和连接生命周期交给社区库；本类只约束地址、预算、大小及安全错误。
 * 调用方必须先提交数据库事务；业务 ACK、认证、验签及重试由对应服务决定。
 */
class HttpClient
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly HttpTargetPolicy $targets,
        private readonly HttpAddressResolver $addresses,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * 发送已冻结签名的通知 URL，不重新签名或改写 query。响应不等于商户业务 ACK。
     *
     * @param \DateTimeImmutable $deadline 早于持久租约到期的绝对截止，包含本次 DNS 时间。
     * @param list<string>|null $allowedHosts 从冻结通知目标取得的域名；null 使用构造时的策略。
     * @throws HttpRequestException 目标拒绝、解析/网络失败、预算或响应大小超限。
     */
    public function get(string $url, \DateTimeImmutable $deadline, ?array $allowedHosts = null): ResponseInterface
    {
        $started = hrtime(true);
        $this->assertDeadline($deadline, $started);
        try {
            $policy = $allowedHosts === null ? $this->targets : $this->targets->forHosts($allowedHosts);
            $uri = $policy->validate($url, true);
            $addresses = $policy->resolve($uri);
        } catch (BusinessException $exception) {
            throw $this->targetFailure($exception, $started);
        }
        return $this->transfer(new Request('GET', $uri, ['Accept' => 'text/plain', 'Cache-Control' => 'no-store']),
            $addresses, $deadline, $started, $this->setting('connect_timeout_seconds', 3, 30) * 1000,
            $this->setting('timeout_seconds', 10, 60) * 1000, $this->setting('response_max_bytes', 65536, 1048576), 16384);
    }

    /**
     * SDK 使用原生 PSR-7 请求；正文应由平台协议显式编码，禁止传入任意 Guzzle/cURL 选项。
     * 登录、刷新和分页共用同一 deadline。超时只表示结果不确定，不证明远端未受理。
     *
     * @param int $maximumBytes 解码后正文上限，最大 4 MiB。
     * @param int $maximumHeaderBytes 响应头上限，最大 64 KiB。
     * @throws HttpRequestException 固定安全分类，不包含凭证、原始 URL 或平台响应。
     * @throws \InvalidArgumentException 请求违反项目合同；SDK 编程错误不能伪装成远端拒绝。
     */
    public function send(RequestInterface $request, PlatformTargetPolicy $policy, \DateTimeImmutable $deadline,
        int $maximumBytes = 65536, int $maximumHeaderBytes = 16384): ResponseInterface
    {
        $started = hrtime(true);
        $this->assertDeadline($deadline, $started);
        $this->assertRequest($request);
        try {
            $uri = HttpTargetPolicy::uri((string) $request->getUri(), 16384, false);
            $addresses = $this->addresses->resolve(trim($uri->getHost(), '[]'), $policy->validate($uri));
        } catch (BusinessException $exception) {
            throw $this->targetFailure($exception, $started);
        }
        return $this->transfer($request, $addresses, $deadline, $started, 3000, 10000, $maximumBytes, $maximumHeaderBytes);
    }

    /**
     * 地址已由对应政策核验；绑定解析结果以消除连接时二次 DNS，TLS 仍按原主机验证。
     * 系统 DNS 不能强制中断；解析返回后扣除时间，剩余预算不足就不建立连接。
     *
     * @param list<string> $addresses 已核验的数值 IP；不接受客户端再次解析的域名。
     * @param int $started DNS 前的单调时钟起点，失败耗时包含全部前置检查。
     */
    private function transfer(RequestInterface $request, array $addresses, \DateTimeImmutable $deadline, int $started,
        int $connectMs, int $timeoutMs, int $maximumBytes, int $maximumHeaderBytes): ResponseInterface
    {
        if ($connectMs > $timeoutMs || $maximumBytes < 1 || $maximumBytes > 4194304
            || $maximumHeaderBytes < 1 || $maximumHeaderBytes > 65536) {
            throw new \InvalidArgumentException('HTTP 预算或响应上限无效');
        }
        $remaining = (int) floor(((float) $deadline->format('U.u') - (float) $this->clock->now()->format('U.u')) * 1000);
        if ($remaining < 1) { throw new HttpRequestException('deadline_elapsed', self::elapsed($started)); }
        $timeoutMs = min($timeoutMs, $remaining);
        $uri = $request->getUri();
        $host = trim($uri->getHost(), '[]');
        $expected = array_map('inet_pton', $addresses);
        // 不复用其他账号的连接、环境代理或 .netrc；连接资源本身由 Guzzle CurlHandler 管理。
        $curl = [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_PATH_AS_IS => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $pins = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $addresses);
            $curl[CURLOPT_RESOLVE] = [$host . ':' . ($uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80)) . ':' . implode(',', $pins)];
        }
        $sink = Utils::streamFor(Utils::tryFopen('php://temp', 'w+b'));
        $received = 0;
        $failure = null;
        // 使用 PSR-7 流装饰器限制已解码正文；返回短写让 Guzzle 中止，不把大响应先全部载入内存。
        $bounded = FnStream::decorate($sink, ['write' => static function (string $chunk) use ($sink, &$received, &$failure, $maximumBytes): int {
            $received += strlen($chunk);
            if ($received > $maximumBytes) {
                // 保留限额内的前缀供失败排查，仍以短写立即终止传输，不能把截断正文判作有效 ACK。
                $remaining = max(0, $maximumBytes - (int) $sink->getSize());
                if ($remaining > 0) { $sink->write(substr($chunk, 0, $remaining)); }
                $failure = 'response_too_large'; return 0;
            }
            return $sink->write($chunk);
        }]);
        try {
            return $this->client->send($request, [
                'allow_redirects' => false, 'http_errors' => false, 'cookies' => false, 'proxy' => '', 'verify' => true,
                'expect' => false, 'decode_content' => 'identity', 'idn_conversion' => false, 'version' => '1.1',
                'connect_timeout' => min($connectMs, $timeoutMs) / 1000, 'timeout' => $timeoutMs / 1000,
                'sink' => $bounded, 'curl' => $curl,
                'on_headers' => static function (ResponseInterface $response) use (&$failure, $maximumHeaderBytes): void {
                    $bytes = strlen($response->getReasonPhrase()) + 17;
                    foreach ($response->getHeaders() as $name => $values) {
                        foreach ($values as $value) { $bytes += strlen($name) + strlen($value) + 4; }
                    }
                    if ($bytes > $maximumHeaderBytes) { $failure = 'header_too_large'; throw new \RuntimeException('响应头超限'); }
                },
                'on_stats' => static function (TransferStats $stats) use (&$failure, $maximumHeaderBytes, $expected, $started): void {
                    $details = $stats->getHandlerStats();
                    // 原始头字节包含中间响应及额外空白，不能仅以解析后的最终头判断上限。
                    if (($details['header_size'] ?? 0) > $maximumHeaderBytes) { $failure = 'header_too_large'; }
                    $response = $stats->getResponse();
                    $errno = $stats->getHandlerErrorData();
                    // 此公开回调在 CurlHandler 的失败重绕/重发之前运行。明确抛出能阻止不明副作用被自动重放。
                    if ($failure !== null || $errno !== 0 || $response === null) {
                        $reason = $failure ?? match ($errno) {
                            CURLE_OPERATION_TIMEDOUT => 'timeout',
                            CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST => 'connect_error',
                            CURLE_SSL_CACERT, CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_CERTPROBLEM => 'tls_error',
                            default => 'transport_error',
                        };
                        throw new HttpRequestException($reason, self::elapsed($started), $response?->getStatusCode());
                    }
                    $connected = @inet_pton((string) ($details['primary_ip'] ?? ''));
                    if ($connected === false || !in_array($connected, $expected, true)) {
                        throw new HttpRequestException('address_mismatch', self::elapsed($started), $response->getStatusCode());
                    }
                },
            ]);
        } catch (HttpRequestException $exception) {
            $prefix = $sink->getSize() > 0 ? substr((string) $sink, 0, 4096) : null;
            $sink->close();
            throw new HttpRequestException($exception->reasonCode, $exception->durationMs, $exception->httpStatus, $prefix);
        } catch (GuzzleException) {
            $prefix = $sink->getSize() > 0 ? substr((string) $sink, 0, 4096) : null;
            $sink->close();
            // 库异常可带完整 URL/正文，不保留为 previous，避免统一日志无意输出秘密。
            throw new HttpRequestException($failure ?? 'transport_error', self::elapsed($started), null, $prefix);
        } catch (\Throwable $exception) {
            $sink->close();
            throw $exception;
        }
    }

    /** PSR-7 校验 HTTP 语法；这里禁止绕过目标地址、正文大小与连接策略的 SDK 请求。 */
    private function assertRequest(RequestInterface $request): void
    {
        $uri = $request->getUri();
        $target = ($uri->getPath() === '' ? '/' : $uri->getPath()) . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());
        $hosts = $request->getHeader('Host');
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        $size = $request->getBody()->getSize();
        if (!in_array($request->getMethod(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)
            || $request->getRequestTarget() !== $target || count($hosts) !== 1
            || !in_array(strtolower($hosts[0]), [strtolower($uri->getAuthority()), strtolower($uri->getHost()) . ':' . $port], true)
            || $size === null || $size > 4194304 || ($size > 0 && (!$request->hasHeader('Content-Type')
                || in_array($request->getMethod(), ['GET', 'HEAD'], true)))) {
            throw new \InvalidArgumentException('SDK 请求目标、方法或正文不符合合同');
        }
        foreach (['transfer-encoding', 'connection', 'proxy-authorization', 'proxy-connection', 'expect', 'upgrade', 'trailer', 'te', 'accept-encoding'] as $name) {
            if ($request->hasHeader($name)) { throw new \InvalidArgumentException('SDK 不能覆盖连接控制头'); }
        }
        if ($request->hasHeader('Content-Length') && $request->getHeader('Content-Length') !== [(string) $size]) {
            throw new \InvalidArgumentException('SDK 正文长度与声明不符');
        }
        $bytes = 0;
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) { $bytes += strlen($name) + strlen($value) + 4; }
        }
        if (count($request->getHeaders()) > 64 || $bytes > 32768) { throw new \InvalidArgumentException('SDK 请求头超限'); }
    }

    private function assertDeadline(\DateTimeImmutable $deadline, int $started): void
    {
        if ($deadline <= $this->clock->now()) { throw new HttpRequestException('deadline_elapsed', self::elapsed($started)); }
    }

    private function targetFailure(BusinessException $exception, int $started): HttpRequestException
    {
        return new HttpRequestException($exception->getCode() === ApiResponseConstant::SERVICE_UNAVAILABLE ? 'dns_error' : 'target_rejected', self::elapsed($started));
    }

    /** 部署配置错误直接失败，不以无界值或静默截断放宽网络预算。 */
    private function setting(string $name, int $default, int $maximum): int
    {
        $value = $this->config->get('http.' . $name, $default);
        if (!is_int($value) || $value < 1 || $value > $maximum) { throw new \LogicException('HTTP 限制配置无效：' . $name); }
        return $value;
    }

    private static function elapsed(int $started): int
    {
        return (int) min(4294967295, (hrtime(true) - $started) / 1000000);
    }
}
