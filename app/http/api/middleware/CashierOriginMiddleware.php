<?php
declare(strict_types=1);

namespace app\http\api\middleware;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use think\Request;
use think\Response;

/** 收银台写接口的浏览器来源限制；与 Session CSRF 和订单随机凭证共同使用，不替代其中任一项。 */
final class CashierOriginMiddleware
{
    /** 旧客户端可以不发送 Origin，但仍必须通过后续 Session token 和订单凭证校验。 */
    public function handle(Request $request, \Closure $next): Response
    {
        $origin = $request->header('Origin', '');
        try {
            $origin = $origin === '' ? '' : (string) new Uri($origin);
            $siteOrigin = (string) new Uri($request->domain());
        }
        catch (InvalidArgumentException) {
            throw new BusinessException('请求来源地址无效', ApiResponseConstant::FORBIDDEN);
        }
        if ($request->header('Sec-Fetch-Site', '') === 'cross-site'
            || ($origin !== '' && $origin !== $siteOrigin)) {
            throw new BusinessException('请求来源与当前站点不一致，请检查访问地址或代理配置', ApiResponseConstant::FORBIDDEN);
        }
        if (strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0])) !== 'application/json'
            || strlen($request->getInput()) > 4096) {
            throw new BusinessException('收银台操作必须使用受限 JSON 正文', ApiResponseConstant::BAD_REQUEST);
        }
        return $next($request);
    }
}
