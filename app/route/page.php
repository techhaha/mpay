<?php
declare(strict_types=1);

use app\http\page\AdminController;
use app\http\page\CashierController;
use app\http\page\HomeController;
use think\facade\Route;

// 只有输出会话/CSRF 的页面初始化 Session；支付结果回跳仍由业务服务核验，页面不确认收款。
Route::rule('/', [HomeController::class, 'index'], 'GET|HEAD')->name('home');
Route::rule('admin', [AdminController::class, 'index'], 'GET|HEAD')->name('admin.page.home');
Route::group('admin', function (): void {
    Route::rule('login', [AdminController::class, 'login'], 'GET|HEAD')->name('admin.page.login');
    Route::rule('account', [AdminController::class, 'account'], 'GET|HEAD')
        ->name('admin.page.account')
        ->middleware('admin.auth');
    Route::rule('orders', [AdminController::class, 'orders'], 'GET|HEAD')
        ->name('admin.page.orders')
        ->middleware('admin.auth');
    Route::rule('dashboard', [AdminController::class, 'dashboard'], 'GET|HEAD')
        ->name('admin.page.dashboard')
        ->middleware('admin.auth');
    Route::rule('receipts', [AdminController::class, 'receipts'], 'GET|HEAD')
        ->name('admin.page.receipts')
        ->middleware('admin.auth');
    Route::rule('notifications', [AdminController::class, 'notifications'], 'GET|HEAD')
        ->name('admin.page.notifications')
        ->middleware('admin.auth');
    Route::rule('ingress', [AdminController::class, 'ingress'], 'GET|HEAD')
        ->name('admin.page.ingress')
        ->middleware('admin.auth');
    Route::rule('audit', [AdminController::class, 'audit'], 'GET|HEAD')
        ->name('admin.page.audit')
        ->middleware('admin.auth');
    Route::rule('configurations', [AdminController::class, 'configurations'], 'GET|HEAD')
        ->name('admin.page.configurations')
        ->middleware('admin.auth');
    Route::rule('plugins', [AdminController::class, 'plugins'], 'GET|HEAD')
        ->name('admin.page.plugins')
        ->middleware('admin.auth');
    Route::rule('settings', [AdminController::class, 'settings'], 'GET|HEAD')
        ->name('admin.page.settings')
        ->middleware('admin.auth');
    Route::rule('about', [AdminController::class, 'about'], 'GET|HEAD')
        ->name('admin.page.about')
        ->middleware('admin.auth');
    Route::rule('applications', [AdminController::class, 'applications'], 'GET|HEAD')
        ->name('admin.page.applications')
        ->middleware('admin.auth');
    Route::rule('maintenance', [AdminController::class, 'runtime'], 'GET|HEAD')
        ->name('admin.page.maintenance')
        ->middleware('admin.auth');
    Route::rule('poll-groups', [AdminController::class, 'pollGroups'], 'GET|HEAD')
        ->name('admin.page.poll-groups')
        ->middleware('admin.auth');
})->middleware('session');
Route::group('cashier', function (): void {
    Route::rule('success', [CashierController::class, 'completed'], 'GET|HEAD')->name(
        'cashier.success',
    );
    Route::rule('pay', [CashierController::class, 'index'], 'GET|HEAD')
        ->name('cashier.pay')
        ->middleware('session');
    Route::rule('return', [CashierController::class, 'back'], 'GET|HEAD')->name('cashier.return');
});
