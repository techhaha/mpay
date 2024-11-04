<?php

namespace app\controller;

use think\facade\View;

class IndexController
{
    public function index()
    {
        if (session('?nickname')) {
            $nickname = session('nickname');
            View::assign('nickname', $nickname);
        }
        return View::fetch();
    }
    public function doc()
    {
        View::assign('domain', \request()->domain());
        return View::fetch();
    }
    public function test()
    {
        $pay = new \payclient\PayClass(['username'=>1531,'password'=>15646]);


        return '123';
    }
}
