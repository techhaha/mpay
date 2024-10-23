<?php

namespace app\controller;

use think\facade\View;
use payclient\LaKaLa;

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
        $key = "0383d7088b6947b68e4a626af119e2bd";

        return $key;
    }
}
