<?php
declare(strict_types=1);

use app\http\page\CashierController;
use app\http\page\AdminController;
use think\facade\Route;

// 只有输出会话/CSRF 的页面初始化 Session；支付结果回跳仍由业务服务核验，页面不确认收款。
Route::rule('/', [AdminController::class, 'index'], 'GET|HEAD')->name('home');
Route::rule('admin', [AdminController::class, 'index'], 'GET|HEAD')->name('admin.page.home');
Route::group('admin', function (): void {
    Route::rule('login', [AdminController::class, 'login'], 'GET|HEAD')->name('admin.page.login');
    Route::rule('account', [AdminController::class, 'account'], 'GET|HEAD')->name('admin.page.account')->middleware('admin.auth');
    Route::rule('orders', [AdminController::class, 'orders'], 'GET|HEAD')->name('admin.page.orders')->middleware('admin.auth');
    foreach (['dashboard', 'receipts', 'notifications', 'ingress', 'audit', 'configurations', 'plugins', 'settings', 'applications', 'runtime'] as $page) {
        Route::rule($page, [AdminController::class, $page], 'GET|HEAD')->name('admin.page.' . $page)->middleware('admin.auth');
    }
    Route::rule('poll-groups', [AdminController::class, 'pollGroups'], 'GET|HEAD')->name('admin.page.poll-groups')->middleware('admin.auth');
})->middleware('session');
Route::group('cashier', function (): void {
    Route::rule('pay', [CashierController::class, 'index'], 'GET|HEAD')->name('cashier.pay')->middleware('session');
    Route::rule('return', [CashierController::class, 'back'], 'GET|HEAD')->name('cashier.return');
});
