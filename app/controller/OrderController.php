<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Order;
use think\facade\View;

class OrderController extends BaseController
{
    public function index()
    {
        $servertime = date('Y-m-d H:i:s', time());
        // 加载配置文件
        $option = \think\facade\Config::load("extendconfig/platform", 'extendconfig');
        View::assign('servertime', $servertime);
        View::assign('options', $option);
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
}
