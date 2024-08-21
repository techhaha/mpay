<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\View;

class ConsoleController extends BaseController
{
    // 后台主页
    public function index()
    {
        return View::fetch();
    }
    // 管理菜单
    public function menu()
    {
        // 加载菜单配置
        $menu = \think\facade\Config::load("extendconfig/menu", 'extendconfig');
        return \json($menu);
    }
    // 首页仪表盘
    public function console()
    {
        return View::fetch();
    }
}
