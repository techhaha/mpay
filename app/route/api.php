<?php
declare(strict_types=1);

use app\http\api\controller\EpayV1Controller;
use app\http\api\controller\CashierController;
use app\http\api\controller\PaymentNotifyController;
use think\facade\Route;

// 易支付合同固定根路径；独立组不挂 Session/CSRF，认证仍为原始签名或 PID/key。
Route::group(function (): void {
    Route::rule('submit.php', [EpayV1Controller::class, 'submit'], 'GET|POST')->name('epay.submit');
    Route::post('mapi.php', [EpayV1Controller::class, 'mapi'])->name('epay.mapi');
    // 查询保留 GET/POST；同路径的 act=refund 由控制器限定实际 POST。
    Route::rule('api.php', [EpayV1Controller::class, 'api'], 'GET|POST')->name('epay.api');
})->middleware('rate', ['rule' => 'epay_ip']);

Route::group('api/cashier', function (): void {
    // 高频订单查询不读写 Session；订单随机凭证由入口及服务校验。
    Route::rule('order', [CashierController::class, 'order'], 'GET|HEAD')->name('cashier.order');
    Route::rule('status', [CashierController::class, 'status'], 'GET|HEAD')->name('cashier.status');
    Route::rule('completion', [CashierController::class, 'completion'], 'GET|HEAD')->name(
        'cashier.completion',
    );
    Route::group(function (): void {
        Route::post('select', [CashierController::class, 'selectType'])->name('cashier.select');
        Route::post('prepare', [CashierController::class, 'prepare'])->name('cashier.prepare');
    })->middleware('cashier.write');
})->middleware('rate', ['rule' => 'cashier_ip']);
// 设备和平台共用 notify；数字定位通道，P 前缀支付号定位原单配置，验签/报文由插件处理。
Route::group('api/pay', function (): void {
    Route::rule(':identifier/notify', [PaymentNotifyController::class, 'notify'], 'GET|POST')
        ->pattern(['identifier' => '(?:[1-9][0-9]{0,19}|P[0-9]{19})'])
        ->name('payment.channel-notify');
})->middleware('rate', ['rule' => 'channel_notify']);
