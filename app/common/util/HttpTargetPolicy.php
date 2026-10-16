<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use think\Config;

/**
 * 商户通知/回跳共用地址格式与可选域名限制；服务器出站限制只用于通知。
 * DNS 结果须由 HTTP 客户端绑定连接，浏览器回跳不解析 DNS 或限制目标网段。
 */
class HttpTargetPolicy
{
    /** @param list<string> $allowedHosts 新订单的域名限制；空列表或 * 表示不限域名。 */
    public function __construct(
        private readonly Config $config,
        private readonly HttpAddressResolver $addresses,
        private readonly array $allowedHosts = [],
    ) {}

    /** 创建本次应用的目标策略；不修改容器共用实例，不从工具内读取业务库。
     * @param list<string> $hosts 已规范化的域名，或用空列表/['*'] 表示不限域名。
     */
    public function forHosts(array $hosts): self
    {
        return new self($this->config, $this->addresses, $hosts);
    }

    /**
     * URI 解析交给 PSR-7；这里仅保留项目拒绝用户信息、模糊主机和商户签名歧义的政策。
     * 平台请求传 merchantQuery=false，保留原始重复 query/点号字段，不套用易支付表单规则。
     *
     * @throws BusinessException 输入不符合项目的地址合同，错误不回显 URL。
     */
    public static function uri(
        string $url,
        int $maximum = 2048,
        bool $merchantQuery = true,
    ): UriInterface {
        if ($maximum < 1 || $maximum > 16384) {
            throw new \InvalidArgumentException('URL 长度上限无效');
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
        } catch (\InvalidArgumentException) {
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
                $host !== 'localhost' &&
                !preg_match(
                    '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D',
                    $host,
                ))
        ) {
            throw new BusinessException(
                '目标必须是无用户信息的标准 HTTP(S) 地址',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        if ($merchantQuery) {
            foreach (
                UrlEncodedForm::parse($uri->getQuery(), $maximum > 2048 ? 256 : 128)
                as $name => $unused
            ) {
                if (preg_match('/[.\s]/', (string) $name)) {
                    throw new BusinessException(
                        '目标 query 字段名存在签名歧义',
                        ApiResponseConstant::VALIDATION_FAILED,
                    );
                }
            }
        }
        return $uri;
    }

    /** 通知和回跳的共同输入规则；仅检查格式与应用域名，浏览器回跳不套用服务器网络限制。 */
    public function validate(string $url, bool $signedRequest = false): UriInterface
    {
        // 目标本身最多 2048 字符；已附加协议参数的完整 GET 请求允许增长。
        $target = self::uri($url, $signedRequest ? 16384 : 2048);
        $allowed = $this->allowedHosts;
        if (
            $allowed !== [] &&
            !in_array('*', $allowed, true) &&
            !$this->developmentAllowed($target) &&
            !in_array(trim($target->getHost(), '[]'), array_map('strtolower', $allowed), true)
        ) {
            throw new BusinessException(
                '目标域名未在应用允许列表中',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        return $target;
    }

    /** 通知建单及发送前额外核验出站端口、字面 IP；DNS 留到连接前，不在订单事务内查询。 */
    public function validateNotification(string $url, bool $signedRequest = false): UriInterface
    {
        $target = $this->validate($url, $signedRequest);
        $development = $this->developmentAllowed($target);
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
        if (filter_var(trim($target->getHost(), '[]'), FILTER_VALIDATE_IP) !== false) {
            $this->addresses->assertAddress(trim($target->getHost(), '[]'), $development);
        } elseif (trim($target->getHost(), '[]') === 'localhost' && !$development) {
            throw new BusinessException(
                '不允许本机目标地址',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        return $target;
    }

    /**
     * 接收 HttpClient 已通过 validateNotification 核验的同一个 URI，连接前解析并校验全部 A/AAAA。
     * URL 格式、端口及字面地址已检查，不在此重复解析；DNS 结果每次重新核验。
     *
     * @return list<string> 客户端须用 CURLOPT_RESOLVE 等等价方式绑定这些地址，禁止跟随重定向。
     * @throws BusinessException 域名无法解析、地址不允许或 CNAME 链超出明确边界。
     */
    public function resolve(UriInterface $target): array
    {
        $development = $this->developmentAllowed($target);
        return $this->addresses->resolve(trim($target->getHost(), '[]'), $development);
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
