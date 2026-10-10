<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use GuzzleHttp\Psr7\Uri;
use think\Request;

/**
 * 本站链接随当前访问域名生成，支持同站多域名及迁移；不绑定固定域名，也不执行 DNS 查询。
 * 只读取 Web 服务器传入的 Host/HTTPS 参数，忽略客户端自行提交的 Forwarded/X-Forwarded-*。
 */
final class SiteUrl
{
    public function __construct(private readonly Request $request)
    {
    }

    /**
     * 当前请求的规范来源；HTTPS 终止代理应由受信任 Web 服务器传递正确 FastCGI 协议参数。
     * CLI 没有访问域名，不能猜测旧站地址；需要链接的 HTTP 协议入口在创建订单前调用。
     *
     * @throws BusinessException Host 缺失或不是单个合法主机，不能把路径、用户信息或控制字符拼进链接。
     */
    public function origin(): string
    {
        $host = (string) $this->request->server('HTTP_HOST', '');
        if ($host === '' || strlen($host) > 320 || preg_match('/[\x00-\x20\x7f\/@?#\\\\]/', $host)) {
            throw new BusinessException('访问域名格式无效', ApiResponseConstant::BAD_REQUEST);
        }
        $secure = in_array(strtolower((string) $this->request->server('HTTPS', '')), ['on', '1'], true)
            || $this->request->server('REQUEST_SCHEME') === 'https' || (string) $this->request->server('SERVER_PORT') === '443';
        try { $uri = new Uri(($secure ? 'https://' : 'http://') . $host); }
        catch (\InvalidArgumentException) { throw new BusinessException('访问域名格式无效', ApiResponseConstant::BAD_REQUEST); }
        $name = trim($uri->getHost(), '[]');
        if ($name === '' || (filter_var($name, FILTER_VALIDATE_IP) === false
            && filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw new BusinessException('访问域名格式无效', ApiResponseConstant::BAD_REQUEST);
        }
        return (string) $uri;
    }

    /** @param array<string,string> $query 固定本站路径的公开参数；外部商户通知 URL 使用独立出站政策。 */
    public function path(string $path, array $query = []): string
    {
        if (!preg_match('#^/[a-zA-Z0-9/_-]+$#D', $path)) { throw new \InvalidArgumentException('本站链接路径无效'); }
        return $this->origin() . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
