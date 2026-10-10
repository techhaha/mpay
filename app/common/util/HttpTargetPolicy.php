<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Config;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

/**
 * 商户目标地址的允许列表及连接前地址核验；DNS 结果必须由 HTTP 客户端绑定使用，不能再自行解析。
 * 特殊网段依据 IANA 登记，保守拒绝隧道/映射地址，避免把嵌入的内网地址带入连接。
 */
class HttpTargetPolicy
{
    /** @param list<string> $allowedHosts 由业务服务显式给出的本次目标域名，不在公共工具中查询数据库。 */
    public function __construct(private readonly Config $config, private readonly HttpAddressResolver $addresses,
        private readonly array $allowedHosts = [])
    {
    }

    /** 创建本次应用/冻结通知的目标策略；不修改容器共用实例，不从工具内读取业务库。
     * @param list<string> $hosts 已规范化的域名。
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
    public static function uri(#[\SensitiveParameter] string $url, int $maximum = 2048, bool $merchantQuery = true): UriInterface
    {
        if ($maximum < 1 || $maximum > 16384) { throw new \InvalidArgumentException('URL 长度上限无效'); }
        if ($url === '' || !mb_check_encoding($url, 'UTF-8') || mb_strlen($url, 'UTF-8') > $maximum
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || preg_match('/%(?![a-fA-F0-9]{2})/', $url)) {
            throw new BusinessException('目标 URL 格式无效', ApiResponseConstant::VALIDATION_FAILED);
        }
        try { $uri = new Uri($url); }
        catch (\InvalidArgumentException) { throw new BusinessException('目标 URL 格式无效', ApiResponseConstant::VALIDATION_FAILED); }
        $host = trim($uri->getHost(), '[]');
        if (!in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getUserInfo() !== '' || $host === ''
            || (filter_var($host, FILTER_VALIDATE_IP) === false && $host !== 'localhost'
                && !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D', $host))) {
            throw new BusinessException('目标必须是无用户信息的标准 HTTP(S) 地址', ApiResponseConstant::VALIDATION_FAILED);
        }
        if ($merchantQuery) {
            foreach (UrlEncodedForm::parse($uri->getQuery(), $maximum > 2048 ? 256 : 128) as $name => $unused) {
                if (preg_match('/[.\s]/', (string) $name)) {
                    throw new BusinessException('目标 query 字段名存在签名歧义', ApiResponseConstant::VALIDATION_FAILED);
                }
            }
        }
        return $uri;
    }

    /** 只做配置和字面地址检查，可用于验签后的输入校验；不在业务事务内触发 DNS。 */
    public function validate(string $url, bool $signedRequest = false): UriInterface
    {
        // 目标本身最多 2048 字符；已附加协议参数的完整 GET 请求允许增长，不放宽主机/端口规则。
        $target = self::uri($url, $signedRequest ? 16384 : 2048);
        $development = $this->developmentAllowed($target);
        $allowed = $this->allowedHosts;
        $ports = $this->config->get('http.allowed_ports', [80, 443]);
        if (!$development && (!is_array($allowed) || !is_array($ports)
            || !in_array(trim($target->getHost(), '[]'), array_map('strtolower', $allowed), true) || !in_array(($target->getPort() ?? ($target->getScheme() === 'https' ? 443 : 80)), $ports, true))) {
            throw new BusinessException('目标域名或端口未在允许列表中', ApiResponseConstant::VALIDATION_FAILED);
        }
        if (filter_var(trim($target->getHost(), '[]'), FILTER_VALIDATE_IP) !== false) {
            $this->addresses->assertAddress(trim($target->getHost(), '[]'), $development);
        } elseif (trim($target->getHost(), '[]') === 'localhost' && !$development) {
            throw new BusinessException('不允许本机目标地址', ApiResponseConstant::VALIDATION_FAILED);
        }
        return $target;
    }

    /**
     * 在每次真正连接前重新解析并校验全部 A/AAAA；只返回允许的数值地址，不缓存授权结论。
     *
     * @return list<string> 客户端须用 CURLOPT_RESOLVE 等等价方式绑定这些地址，禁止跟随重定向。
     * @throws BusinessException 域名无法解析、地址不允许或 CNAME 链超出明确边界。
     */
    public function resolve(UriInterface $target): array
    {
        $this->validate((string) $target, true);
        $development = $this->developmentAllowed($target);
        return $this->addresses->resolve(trim($target->getHost(), '[]'), $development);
    }

    /** 开发放行同时匹配环境、主机和端口，不能靠一个全局“关闭 SSRF”开关启用。 */
    private function developmentAllowed(UriInterface $target): bool
    {
        return in_array($this->config->get('mpay.environment'), ['development', 'testing'], true)
            && in_array(trim($target->getHost(), '[]'), $this->config->get('http.development_hosts', []), true)
            && in_array(($target->getPort() ?? ($target->getScheme() === 'https' ? 443 : 80)), $this->config->get('http.development_ports', []), true);
    }
}
