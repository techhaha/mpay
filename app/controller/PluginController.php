<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\View;

class PluginController extends BaseController
{
    public function index()
    {
        return View::fetch();
    }
}
