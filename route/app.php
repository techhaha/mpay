<?php

use think\facade\Route;

// 页面跳转支付
Route::rule('submit', 'Pay/submit');
// API支付
Route::rule('mapi', 'Pay/mapi');
// 收银台
Route::rule('/Pay/console/[:order_id]', '/Pay/console');
// 查询订单状态
Route::rule('getOrderState/[:order_id]', 'Pay/getOrderState');
// 监控新订单
Route::rule('checkOrder/[:pid]/[:sign]', 'Pay/checkOrder');
// 处理收款通知
Route::rule('payHeart/[:pid]/[:aid]/[:sign]', 'Pay/payHeart');
// 监听收款通知
Route::rule('checkPayResult', 'Pay/checkPayResult');

// API多级控制器
Route::rule('api/:controller/:methon', 'api.:controller/:methon');
