<?php
declare(strict_types=1);

namespace app\http\middleware;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use think\Request;
use think\Response;
use think\Session;

/** 后台及同源收银台写请求的 Session CSRF 校验；须先执行 SessionInit，不适用于易支付或设备协议。 */
final class CsrfMiddleware
{
    public function __construct(private readonly Session $session) {}

    /**
     * 对非只读原始 HTTP 方法校验 token；权限和业务幂等仍由后续入口分别检查。
     *
     * @param \Closure(Request): Response $next 已通过 CSRF 校验后继续的处理环节。
     * @throws BusinessException 当前 Session 没有 token 或请求头与其不匹配。
     */
    public function handle(Request $request, \Closure $next): Response
    {
        if (!in_array($request->method(true), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $expected = $this->session->get('csrf_token');
            $actual = $request->header('X-Csrf-Token', '');
            if (
                !is_string($expected) ||
                $expected === '' ||
                !is_string($actual) ||
                !hash_equals($expected, $actual)
            ) {
                throw new BusinessException(
                    '页面已过期，请刷新后重试',
                    ApiResponseConstant::CSRF_INVALID,
                );
            }
        }
        return $next($request);
    }
}
