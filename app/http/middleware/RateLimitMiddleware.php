<?php
declare(strict_types=1);

namespace app\http\middleware;

use app\service\system\access\RequestRateLimiter;
use Closure;
use LogicException;
use think\Request;
use think\Response;

/** 路由显式声明规则；管理员额度须排在认证之后，匿名入口只使用服务器连接 IP。 */
final class RateLimitMiddleware
{
    public function __construct(private readonly RequestRateLimiter $limiter) {}

    /**
     * @param Closure(Request):Response $next
     * @param array{rule:string,identity?:'ip'|'admin',action?:string} $policy 路由静态配置，不读取请求覆盖。
     */
    public function handle(Request $request, Closure $next, array $policy): Response
    {
        if (!in_array($policy['identity'] ?? 'ip', ['ip', 'admin'], true)) {
            throw new LogicException('限流身份配置无效');
        }
        $rule = $policy['rule'];
        if (($policy['identity'] ?? 'ip') === 'admin') {
            $identity = $request->middleware('admin_identity');
            if (!is_array($identity) || !is_string($identity['id'] ?? null)) {
                throw new LogicException('管理员限流必须在认证后执行');
            }
            if ($rule === 'admin') {
                $rule = in_array($request->method(), ['GET', 'HEAD'], true)
                    ? 'admin_read'
                    : 'admin_write';
            }
            $key = $identity['id'] . ':' . ($policy['action'] ?? '');
        } else {
            $ip = (string) $request->server('REMOTE_ADDR', '');
            // 不使用请求头或表单内的客户端 IP；无连接地址时共用受限桶，不能创建无限个身份。
            $key =
                filter_var($ip, FILTER_VALIDATE_IP) === false
                    ? 'unknown'
                    : inet_ntop(inet_pton($ip));
        }
        $this->limiter->check($rule, $key);
        return $next($request);
    }
}
