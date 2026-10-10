<?php
declare(strict_types=1);

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\facade\Route;
use think\exception\HttpException;

// ThinkPHP 的路由文件入口；页面、后台 API、易支付及收银台使用原生分组和命名路由。
// 列表为程序内固定文件名，不从请求拼接路径，也不启用目录自动扫描或控制器兜底。
foreach (['install', 'page', 'admin', 'api'] as $entry) {
    require app()->getAppPath() . 'route/' . $entry . '.php';
}


// 统一未命中处理：从框架已登记的固定路径读取允许方法，不重复声明一套 any 路由或方法映射。
// 入口由上述显式规则登记；设备通道路径带受 pattern 限制的选择器，未知路径保持 404。
Route::miss(static function (think\Request $request, think\Route $router): never {
    $allowed = [];
    foreach ($router->getRule(ltrim($request->pathinfo(), '/')) as $rule) {
        if (!$rule->isMiss() && $rule->getMethod() !== '*') {
            $allowed = array_merge($allowed, explode('|', strtoupper($rule->getMethod())));
        }
    }
    $allowed = array_values(array_unique($allowed));
    if ($allowed !== [] && !in_array($request->method(true), $allowed, true)) {
        throw new HttpException(405, '', null, ['Allow' => implode(', ', $allowed)]);
    }
    throw new HttpException(404);
});
