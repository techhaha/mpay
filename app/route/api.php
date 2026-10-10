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
    Route::rule('api.php', [EpayV1Controller::class, 'api'], 'GET|POST')->name('epay.api');
});

Route::group('api/cashier', function (): void {
    // 高频订单查询不读写 Session；订单随机凭证由入口及服务校验。
    Route::rule('order', [CashierController::class, 'order'], 'GET|HEAD')->name('cashier.order');
    Route::group(function (): void {
        Route::post('select', [CashierController::class, 'selectType'])->name('cashier.select');
        Route::post('prepare', [CashierController::class, 'prepare'])->name('cashier.prepare');
    })->middleware('cashier.write');
});
// 通道通知使用独立组；只允许 POST，原始正文由插件验签，来源与通道由服务核对。
Route::group('api/pay', function (): void {
    Route::post(':channelId/notify', [PaymentNotifyController::class, 'notify'])
        ->pattern(['channelId' => '[1-9][0-9]{0,19}'])->name('payment.channel-notify');

});
