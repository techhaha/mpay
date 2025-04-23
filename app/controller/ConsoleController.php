<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Order;
use think\facade\View;

class ConsoleController extends BaseController
{
    // 后台主页
    public function index()
    {
        View::assign('version', 'V1');
        return View::fetch();
    }
    // 管理菜单
    public function menu()
    {
        // 加载菜单配置
        $menu = \think\facade\Config::load("extend/menu", 'extend');
        return json($menu);
    }
    // 管理菜单
    public function message()
    {
        // 加载菜单配置
        $message = \Plugin::getNotifyMessage();
        if (empty($message)) {
            $message = [
                ["id" => 1, "title" => "应用更新", "children" => []],
                ["id" => 2, "title" => "官方消息", "children" => []],
            ];
        }
        return json($message);
    }
    // 首页仪表盘
    public function console()
    {
        // 查询近32天的订单
        $orders = Order::where([['state', '=', 1], ['create_time', '>', date('Y-m-d 00:00:00', strtotime('-32 days'))]])->select();
        $income = $this->getRevenueData($orders);
        View::assign($income);
        $servertime = date('Y-m-d H:i:s', time());
        View::assign('servertime', $servertime);
        return View::fetch();
    }
    // 获取收入数据总览
    private function getRevenueData($orders)
    {
        // 时间段
        $month_start = date('Y-m-01 00:00:00');
        $month_end = date('Y-m-d 23:59:59', strtotime('last day of this month'));
        $week_start = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $week_end = date('Y-m-d 23:59:59', strtotime('next monday') - 1);
        $yesterday_start = date('Y-m-d 00:00:00', strtotime('yesterday'));
        $yesterday_end = date('Y-m-d 23:59:59', strtotime('yesterday'));
        $today_start = date('Y-m-d 00:00:00');
        $today_end = date('Y-m-d 23:59:59');
        // 本月流水
        $month_income = $orders->whereBetween('create_time', [$month_start, $month_end])->column('really_price');
        // 本周流水
        $week_income = $orders->whereBetween('create_time', [$week_start, $week_end])->column('really_price');
        // 昨日流水
        $yesterday_income = $orders->whereBetween('create_time', [$yesterday_start, $yesterday_end])->column('really_price');
        // 今天流水
        $today_income = $orders->whereBetween('create_time', [$today_start, $today_end])->column('really_price');
        // 收入数据
        $income = [
            'month_income' => \array_sum($month_income),
            'week_income' => \array_sum($week_income),
            'yesterday_income' => \array_sum($yesterday_income),
            'today_income' => \array_sum($today_income),
        ];
        return $income;
    }
}
