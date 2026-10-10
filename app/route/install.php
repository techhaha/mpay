<?php
declare(strict_types=1);

use app\http\install\controller\InstallController;
use think\facade\Route;

// 安装引导先选择本次协议的 Cookie，再初始化 Session；安装成功后整个入口由安装锁关闭。
Route::group(function (): void {
    Route::rule('install', [InstallController::class, 'index'], 'GET|HEAD')->name('install.page');
    Route::group('installapi', function (): void {
        Route::rule('environment', [InstallController::class, 'environment'], 'GET|HEAD')->name('install.environment');
        Route::post('check', [InstallController::class, 'check'])->name('install.check')->middleware('admin.write');
        Route::post('install', [InstallController::class, 'install'])->name('install.execute')->middleware('admin.write');
    });
})->middleware([app\http\install\middleware\InstallMiddleware::class, 'session']);
