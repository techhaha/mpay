<?php

declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\Request;
use think\facade\Session;
use app\model\User;

class UserController extends BaseController
{
    protected $middleware = ['Auth' => ['except' => ['login']]];

    public function login(Request $request)
    {
        $login_info = $request->post();
        $userinfo = self::checkUser($login_info);
        if ($userinfo['code'] === 0) {
            Session::set('userid', $userinfo['data']->id);
            Session::set('nickname', $userinfo['data']->nickname);
            Session::set('userrole', $userinfo['data']->role);
            Session::set('islogin', true);
            return json(\backMsg(0, 'ok'));
        } else {
            return json($userinfo);
        }
    }
    public function logout()
    {
        Session::clear();
        return json(\backMsg(0, '注销成功'));
    }
    private function checkUser(array $login_info): array
    {
        $username = $login_info['username'];
        $password = $login_info['password'];
        $userinfo = User::where('username', $username)->find();
        if ($userinfo) {
            if ($password === $userinfo->password) {
                return ['code' => 0, 'data' => $userinfo];
            } else {
                return \backMsg(1, '登陆密码错误');
            }
        } else {
            return \backMsg(2, '用户不存在');
        }
    }
}
