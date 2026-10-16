<?php
declare(strict_types=1);

namespace app\http\api\middleware;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Request;
use think\Response;

/** 收银台写接口的跨站标识和 JSON 边界；与 Session CSRF 和订单随机凭证共同使用。 */
final class CashierOriginMiddleware
{
    /** 不比较 Origin 与服务器协议；缺少浏览器跨站标识仍须通过后续 CSRF 和订单凭证校验。 */
    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->header('Sec-Fetch-Site', '') === 'cross-site') {
            throw new BusinessException('不允许跨站提交收银台操作', ApiResponseConstant::FORBIDDEN);
        }
        if (
            strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0])) !==
                'application/json' ||
            strlen($request->getInput()) > 4096
        ) {
            throw new BusinessException(
                '收银台操作必须使用受限 JSON 正文',
                ApiResponseConstant::BAD_REQUEST,
            );
        }
        return $next($request);
    }
}
