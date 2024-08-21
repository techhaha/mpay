<?php

declare(strict_types=1);

namespace app\middleware;

class Auth
{
    /**
     * 处理请求
     *
     * @param \think\Request $request
     * @param \Closure       $next
     */
    public function handle($request, \Closure $next)
    {
        // 登陆状态
        $islogin = session('?islogin');

        if ($islogin) {
            return $next($request);
        } else {
            $method = $request->isJson();
            if ($method) {
                return \json(\backMsg(404, '身份过期，请重新登陆'));
            }
            return redirect('/User/login');
        }
    }
}
