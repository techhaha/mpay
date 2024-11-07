<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\View;
use think\Request;

class PluginController extends BaseController
{
    // 插件管理页
    public function index()
    {
        return View::fetch();
    }
}
