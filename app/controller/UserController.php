<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\View;

class UserController extends BaseController
{
    protected $middleware = ['Auth' => ['except' => ['login']]];
    // 用户中心
    public function index()
    {
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
}
