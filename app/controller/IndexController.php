<?php

namespace app\controller;

use think\facade\Log;
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
        $info = request()->post();
        $action = isset($info['action']) ? $info['action'] : '';
        if ($action === 'mpay') {
            $data = json_decode($info['data'], true);
            $config = \think\facade\Config::load("payconfig/{$data['pid']}_{$data['aid']}", 'payconfig');
            $payclient_path = "\\payclient\\Mpay";
            $Payclient = new $payclient_path($info, $config);
            $res = $Payclient->notify($info);
            return $res;
        } else {
            return 202;
        }
    }
}
