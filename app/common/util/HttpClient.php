<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\exception\HttpRequestException;
use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use think\Config;

/**
 * Guzzle 的应用出站边界：平台目标由 SDK 决定，商户通知另按应用授权检查目标。
 * HTTP 消息、流和连接生命周期交给社区库；SDK 正常连接配置地址，通知另做 DNS/IP 限制及绑定。
 * 调用方必须先提交数据库事务；业务 ACK、认证、验签及重试由对应服务决定。
 */
class HttpClient
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly HttpTargetPolicy $targets,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {}

    /**
     * 发送已冻结签名的通知 URL，不重新签名或改写 query。响应不等于商户业务 ACK。
     *
     * @param DateTimeImmutable $deadline 早于持久租约到期的绝对截止，包含本次 DNS 时间。
     * @throws HttpRequestException 目标拒绝、解析/网络失败、预算或响应大小超限。
     */
    public function get(string $url, DateTimeImmutable $deadline): ResponseInterface
    {
        $started = hrtime(true);
        $this->assertDeadline($deadline, $started);
        try {
            // 旧责任沿冻结目标发送；格式、端口、开发例外和 DNS/IP 在同一入口处理。
            ['uri' => $uri, 'addresses' => $addresses] = $this->targets->resolveNotification($url);
        } catch (BusinessException $exception) {
            throw $this->targetFailure($exception, $started);
        }
        return $this->transfer(
            new Request('GET', $uri, ['Accept' => 'text/plain', 'Cache-Control' => 'no-store']),
            $addresses,
            $deadline,
            $started,
            $this->setting('connect_timeout_seconds', 3, 30) * 1000,
            $this->setting('timeout_seconds', 10, 60) * 1000,
            $this->setting('response_max_bytes', 65536, 1048576),
            16384,
        );
    }

    /**
     * SDK 使用原生 PSR-7 请求；正文应由平台协议显式编码，禁止传入任意 Guzzle/cURL 选项。
     * 平台地址和接口规则由 SDK 负责；本入口只核验 URL 格式，不限制公网或内网。
     * 主机解析和连接由 Guzzle/cURL 处理，TLS、重定向、预算和大小规则与通知传输共用。
     * 登录、刷新和本轮查单共用同一 deadline。超时只表示结果不确定，不证明远端未受理。
     *
     * @param int $maximumBytes 解码后正文上限，最大 4 MiB。
     * @param int $maximumHeaderBytes 响应头上限，最大 64 KiB。
     * @throws HttpRequestException 固定安全分类，不包含凭证、原始 URL 或平台响应。
     * @throws InvalidArgumentException 请求或响应大小上限无效；不能伪装成远端拒绝。
     */
    public function send(
        RequestInterface $request,
        DateTimeImmutable $deadline,
        int $maximumBytes = 65536,
        int $maximumHeaderBytes = 16384,
    ): ResponseInterface {
        $started = hrtime(true);
        $this->assertDeadline($deadline, $started);
        // PSR-7/Guzzle 处理 HTTP 语法；这里限制体积及 URL 格式，不对 SDK 目标套用通知网段规则。
        $size = $request->getBody()->getSize();
        $headerBytes = 0;
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headerBytes += strlen($name) + strlen($value) + 4;
            }
        }
        if (
            $size === null ||
            $size > 4194304 ||
            count($request->getHeaders()) > 64 ||
            $headerBytes > 32768
        ) {
            throw new InvalidArgumentException('SDK 请求正文或请求头大小超限');
        }
        try {
            $uri = HttpTargetPolicy::uri((string) $request->getUri(), HttpTargetPolicy::MAX_REQUEST_URL_LENGTH);
            if ($uri->getFragment() !== '') {
                throw new BusinessException('请求地址不能包含片段', ApiResponseConstant::VALIDATION_FAILED);
            }
        } catch (BusinessException $exception) {
            throw $this->targetFailure($exception, $started);
        }
        return $this->transfer(
            $request,
            null,
            $deadline,
            $started,
            3000,
            10000,
            $maximumBytes,
            $maximumHeaderBytes,
        );
    }

    /**
     * 通知绑定已核验地址并核对实际连接 IP；SDK 传 null，使用客户端正常的主机解析与连接。
     * 两种入口共用 TLS、截止及响应上限；通知前置 DNS 时间同样计入总预算。
     *
     * @param list<string>|null $addresses 通知的已核验 IP；null 表示 SDK 正常连接，不执行网段筛选。
     * @param int $started DNS 前的单调时钟起点，失败耗时包含全部前置检查。
     */
    private function transfer(
        RequestInterface $request,
        ?array $addresses,
        DateTimeImmutable $deadline,
        int $started,
        int $connectMs,
        int $timeoutMs,
        int $maximumBytes,
        int $maximumHeaderBytes,
    ): ResponseInterface {
        if (
            $connectMs > $timeoutMs ||
            $maximumBytes < 1 ||
            $maximumBytes > 4194304 ||
            $maximumHeaderBytes < 1 ||
            $maximumHeaderBytes > 65536
        ) {
            throw new InvalidArgumentException('HTTP 预算或响应上限无效');
        }
        $remaining = (int) floor(
            ((float) $deadline->format('U.u') - (float) $this->clock->now()->format('U.u')) * 1000,
        );
        if ($remaining < 1) {
            throw new HttpRequestException('deadline_elapsed', self::elapsed($started));
        }
        $timeoutMs = min($timeoutMs, $remaining);
        $uri = $request->getUri();
        $host = trim($uri->getHost(), '[]');
        $expected = $addresses === null ? null : array_map('inet_pton', $addresses);
        // 不复用其他账号的连接、环境代理或 .netrc；连接资源本身由 Guzzle CurlHandler 管理。
        $curl = [
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ];
        if ($addresses !== null && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $pins = array_map(
                static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
                $addresses,
            );
            $curl[CURLOPT_RESOLVE] = [
                $host .
                ':' .
                ($uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80)) .
                ':' .
                implode(',', $pins),
            ];
        }
        $sink = Utils::streamFor(Utils::tryFopen('php://temp', 'w+b'));
        $received = 0;
        $failure = null;
        // 使用 PSR-7 流装饰器限制已解码正文；返回短写让 Guzzle 中止，不把大响应先全部载入内存。
        $bounded = FnStream::decorate($sink, [
            'write' => static function (string $chunk) use (
                $sink,
                &$received,
                &$failure,
                $maximumBytes,
            ): int {
                $received += strlen($chunk);
                if ($received > $maximumBytes) {
                    // 保留限额内的前缀供失败排查，仍以短写立即终止传输，不能把截断正文判作有效 ACK。
                    $remaining = max(0, $maximumBytes - (int) $sink->getSize());
                    if ($remaining > 0) {
                        $sink->write(substr($chunk, 0, $remaining));
                    }
                    $failure = 'response_too_large';
                    return 0;
                }
                return $sink->write($chunk);
            },
        ]);
        try {
            return $this->client->send($request, [
                'allow_redirects' => false,
                'http_errors' => false,
                'cookies' => false,
                'proxy' => '',
                'verify' => true,
                'expect' => false,
                'decode_content' => 'identity',
                'idn_conversion' => false,
                'version' => '1.1',
                'connect_timeout' => min($connectMs, $timeoutMs) / 1000,
                'timeout' => $timeoutMs / 1000,
                'sink' => $bounded,
                'curl' => $curl,
                'on_headers' => static function (ResponseInterface $response) use (
                    &$failure,
                    $maximumHeaderBytes,
                ): void {
                    $bytes = strlen($response->getReasonPhrase()) + 17;
                    foreach ($response->getHeaders() as $name => $values) {
                        foreach ($values as $value) {
                            $bytes += strlen($name) + strlen($value) + 4;
                        }
                    }
                    if ($bytes > $maximumHeaderBytes) {
                        $failure = 'header_too_large';
                        throw new RuntimeException('响应头超限');
                    }
                },
                'on_stats' => static function (TransferStats $stats) use (
                    &$failure,
                    $maximumHeaderBytes,
                    $expected,
                    $started,
                ): void {
                    $details = $stats->getHandlerStats();
                    // 原始头字节包含中间响应及额外空白，不能仅以解析后的最终头判断上限。
                    if (($details['header_size'] ?? 0) > $maximumHeaderBytes) {
                        $failure = 'header_too_large';
                    }
                    $response = $stats->getResponse();
                    $errno = $stats->getHandlerErrorData();
                    // 此公开回调在 CurlHandler 的失败重绕/重发之前运行。明确抛出能阻止不明副作用被自动重放。
                    if ($failure !== null || $errno !== 0 || $response === null) {
                        $reason =
                            $failure ??
                            match ($errno) {
                                CURLE_OPERATION_TIMEDOUT => 'timeout',
                                CURLE_COULDNT_CONNECT,
                                CURLE_COULDNT_RESOLVE_HOST
                                    => 'connect_error',
                                CURLE_SSL_CACERT,
                                CURLE_SSL_CONNECT_ERROR,
                                CURLE_SSL_CACERT_BADFILE,
                                CURLE_SSL_CERTPROBLEM
                                    => 'tls_error',
                                default => 'transport_error',
                            };
                        throw new HttpRequestException(
                            $reason,
                            self::elapsed($started),
                            $response?->getStatusCode(),
                        );
                    }
                    if ($expected !== null) {
                        $connected = @inet_pton((string) ($details['primary_ip'] ?? ''));
                        if ($connected === false || !in_array($connected, $expected, true)) {
                            throw new HttpRequestException(
                                'address_mismatch',
                                self::elapsed($started),
                                $response->getStatusCode(),
                            );
                        }
                    }
                },
            ]);
        } catch (HttpRequestException | GuzzleException $exception) {
            $prefix = $sink->getSize() > 0 ? substr((string) $sink, 0, 4096) : null;
            $sink->close();
            // 统一收尾并保留已分类错误；库异常可能带秘密，不保存其原文或 previous。
            throw new HttpRequestException(
                $exception instanceof HttpRequestException ? $exception->reasonCode : ($failure ?? 'transport_error'),
                $exception instanceof HttpRequestException ? $exception->durationMs : self::elapsed($started),
                $exception instanceof HttpRequestException ? $exception->httpStatus : null,
                $prefix,
            );
        } catch (Throwable $exception) {
            $sink->close();
            throw $exception;
        }
    }

    private function assertDeadline(DateTimeImmutable $deadline, int $started): void
    {
        if ($deadline <= $this->clock->now()) {
            throw new HttpRequestException('deadline_elapsed', self::elapsed($started));
        }
    }

    private function targetFailure(BusinessException $exception, int $started): HttpRequestException
    {
        return new HttpRequestException(
            $exception->getCode() === ApiResponseConstant::SERVICE_UNAVAILABLE
                ? 'dns_error'
                : 'target_rejected',
            self::elapsed($started),
        );
    }

    /** 部署配置错误直接失败，不以无界值或静默截断放宽网络预算。 */
    private function setting(string $name, int $default, int $maximum): int
    {
        $value = $this->config->get('http.' . $name, $default);
        if (!is_int($value) || $value < 1 || $value > $maximum) {
            throw new LogicException('HTTP 限制配置无效：' . $name);
        }
        return $value;
    }

    private static function elapsed(int $started): int
    {
        return (int) min(4294967295, (hrtime(true) - $started) / 1000000);
    }
}
