<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Order;
use app\model\User;
use think\facade\View;

class OrderController extends BaseController
{
    public function index()
    {
        $servertime = date('Y-m-d H:i:s', time());
        View::assign('servertime', $servertime);
        return View::fetch();
    }
    public function showOrder()
    {
        $id = $this->request->get('id');
        $order = Order::showOrderDetail($id);
        if ($order) {
            View::assign($order);
            return View::fetch();
        } else {
            return '订单不存在';
        }
    }
    public function testPay()
    {
        $pid = 1000;
        if (session('?pid')) {
            $pid = session('pid');
        }
        View::assign('pid', $pid);
        $key = User::where('pid', $pid)->where('state', 1)->value('secret_key');
        if (!$key) {
            return '用户禁用或不存在';
        }
        View::assign('key', $key);
        return View::fetch();
    }
}
