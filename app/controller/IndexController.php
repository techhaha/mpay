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
    public function test()
    {
        return runtime_path();
    }
}
