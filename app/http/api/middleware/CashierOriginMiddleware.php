<?php
declare(strict_types=1);

namespace app\http\api\middleware;

use app\common\constant\ApiResponseConstant;
use app\common\util\SiteUrl;
use app\exception\BusinessException;
use think\Request;
use think\Response;

/** 收银台写接口的浏览器来源限制；与 Session CSRF 和订单随机凭证共同使用，不替代其中任一项。 */
final class CashierOriginMiddleware
{
    public function __construct(private readonly SiteUrl $site)
    {
    }

    /** 旧客户端可以不发送 Origin，但仍必须通过后续 Session token 和订单凭证校验。 */
    public function handle(Request $request, \Closure $next): Response
    {
        $origin = $request->header('Origin', '');
        if ($request->header('Sec-Fetch-Site', '') === 'cross-site'
            || ($origin !== '' && $origin !== $this->site->origin())) {
            throw new BusinessException('仅允许本站收银台提交操作', ApiResponseConstant::FORBIDDEN);
        }
        if (strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0])) !== 'application/json'
            || strlen($request->getInput()) > 4096) {
            throw new BusinessException('收银台操作必须使用受限 JSON 正文', ApiResponseConstant::BAD_REQUEST);
        }
        return $next($request);
    }
}
