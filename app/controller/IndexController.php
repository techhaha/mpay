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
        $key = "0383d7088b6947b68e4a626af119e2bd";

        return $key;
    }
}
