<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use think\Config;

/**
 * 商户通知/回跳共用地址格式与可选域名限制；服务器出站限制只用于通知。
 * DNS 结果须由 HTTP 客户端绑定连接，浏览器回跳不解析 DNS 或限制目标网段。
 */
class HttpTargetPolicy
{
    /** 原始业务目标与完整 HTTP 请求分别使用明确长度上限。 */
    public const MAX_URL_LENGTH = 2048;
    public const MAX_REQUEST_URL_LENGTH = 16384;

    public function __construct(
        private readonly Config $config,
        private readonly HttpAddressResolver $addresses,
    ) {}

    /**
     * URI 解析交给 PSR-7，主机名用原生规则校验，允许内网单标签主机名；不在格式层限制网段。
     * 保留 HTTP(S)、长度及无用户信息要求，不解释协议 query。
     * 商户签名 query 由 UrlEncodedForm::parseQuery 单次解析，平台 SDK 保留自身原始参数规则。
     *
     * @throws BusinessException 输入不符合项目的地址合同，错误不回显 URL。
     */
    public static function uri(
        string $url,
        int $maximum = self::MAX_URL_LENGTH,
    ): UriInterface {
        if ($maximum < 1 || $maximum > self::MAX_REQUEST_URL_LENGTH) {
            throw new InvalidArgumentException('URL 长度上限无效');
        }
        if (
            $url === '' ||
            !mb_check_encoding($url, 'UTF-8') ||
            mb_strlen($url, 'UTF-8') > $maximum ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $url) ||
            preg_match('/%(?![a-fA-F0-9]{2})/', $url)
        ) {
            throw new BusinessException(
                '目标 URL 格式无效',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        try {
            $uri = new Uri($url);
        } catch (InvalidArgumentException) {
            throw new BusinessException(
                '目标 URL 格式无效',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $host = trim($uri->getHost(), '[]');
        if (
            !in_array($uri->getScheme(), ['http', 'https'], true) ||
            $uri->getUserInfo() !== '' ||
            $host === '' ||
            (filter_var($host, FILTER_VALIDATE_IP) === false &&
                filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)
        ) {
            throw new BusinessException(
                '目标必须是无用户信息的标准 HTTP(S) 地址',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        return $uri;
    }

    /**
     * 新订单的浏览器回跳只核验格式及应用域名，不解析 DNS 或限制目标网段。
     *
     * @param list<string> $allowedHosts 当前应用的允许域名；空列表或 * 表示不限域名。
     */
    public function validate(string $url, array $allowedHosts = []): UriInterface
    {
        $target = self::uri($url);
        $development = $this->developmentAllowed($target);
        $this->assertAllowedHost($target, $allowedHosts, $development);
        return $target;
    }

    /**
     * 新通知目标额外核验端口和字面地址；事务内不查询 DNS。
     *
     * @param list<string> $allowedHosts 当前应用的允许域名，不保存在共享对象中。
     */
    public function validateNotification(string $url, array $allowedHosts = []): UriInterface
    {
        $target = self::uri($url);
        $development = $this->developmentAllowed($target);
        $this->assertAllowedHost($target, $allowedHosts, $development);
        $this->assertNotificationPort($target, $development);
        $this->addresses->literalAddress(trim($target->getHost(), '[]'), $development);
        return $target;
    }

    /**
     * 发送冻结通知前一次核验完整 URI、端口和 DNS/IP；不重新应用当前应用域名列表。
     * 开发例外在本次流程只判定一次，字面 IP 也只由解析器核验一次。
     *
     * @return array{uri:UriInterface,addresses:list<string>} 客户端须绑定这些地址并禁止重定向。
     */
    public function resolveNotification(string $url): array
    {
        $target = self::uri($url, self::MAX_REQUEST_URL_LENGTH);
        $development = $this->developmentAllowed($target);
        $this->assertNotificationPort($target, $development);
        return [
            'uri' => $target,
            'addresses' => $this->addresses->resolve(trim($target->getHost(), '[]'), $development),
        ];
    }

    /** @param list<string> $allowedHosts 仅新订单调用，开发例外仍须匹配既有环境/主机/端口。 */
    private function assertAllowedHost(UriInterface $target, array $allowedHosts, bool $development): void
    {
        if (
            $allowedHosts !== [] &&
            !in_array('*', $allowedHosts, true) &&
            !$development &&
            !in_array(trim($target->getHost(), '[]'), array_map('strtolower', $allowedHosts), true)
        ) {
            throw new BusinessException(
                '目标域名未在应用允许列表中',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
    }

    /** 只检查通知端口，不在内部重算开发例外或重复解析 URL。 */
    private function assertNotificationPort(UriInterface $target, bool $development): void
    {
        $ports = $this->config->get('http.allowed_ports', [80, 443]);
        if (!$development) {
            if (
                !is_array($ports) ||
                !in_array(
                    $target->getPort() ?? ($target->getScheme() === 'https' ? 443 : 80),
                    $ports,
                    true,
                )
            ) {
                throw new BusinessException(
                    '目标端口未在允许列表中',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
        }
    }

    /** 开发放行同时匹配环境、主机和端口，不能靠一个全局“关闭 SSRF”开关启用。 */
    private function developmentAllowed(UriInterface $target): bool
    {
        return in_array($this->config->get('mpay.environment'), ['development', 'testing'], true) &&
            in_array(
                trim($target->getHost(), '[]'),
                $this->config->get('http.development_hosts', []),
                true,
            ) &&
            in_array(
                $target->getPort() ?? ($target->getScheme() === 'https' ? 443 : 80),
                $this->config->get('http.development_ports', []),
                true,
            );
    }
}
