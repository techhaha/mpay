<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\View;
use app\model\User;

class UserController extends BaseController
{
    protected $middleware = ['Auth' => ['except' => ['login']]];
    // 用户中心
    public function index()
    {
        $userinfo = User::find(\session('userid'))->toArray();
        View::assign($userinfo);
        View::assign('url', $this->request->domain().'/');
        $sign = md5($userinfo['pid'] . $userinfo['secret_key']);
        View::assign('orderurl', $this->request->domain() . "/checkOrder/{$userinfo['pid']}/{$sign}");
        return View::fetch();
    }
    // 登陆视图
    public function login()
    {
        if (session('?islogin')) {
            return redirect('/Console/index');
        }
        return View::fetch();
    }
    // 修改用户
    public function setUser()
    {
        $userinfo = User::find(session('userid'))->toArray();
        View::assign($userinfo);
        return View::fetch();
    }
}
