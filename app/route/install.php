<?php
declare(strict_types=1);

use app\http\install\controller\InstallController;
use think\facade\Route;

// 安装组先检查完成锁，未完成安装时限制连接检查/执行频率，再初始化 Session。
Route::group(function (): void {
    Route::rule('install', [InstallController::class, 'index'], 'GET|HEAD')->name('install.page');
    Route::group('installapi', function (): void {
        Route::rule('environment', [InstallController::class, 'environment'], 'GET|HEAD')->name(
            'install.environment',
        );
        Route::post('check', [InstallController::class, 'check'])
            ->name('install.check')
            ->middleware('rate', ['rule' => 'install'])
            ->middleware('admin.write');
        Route::post('install', [InstallController::class, 'install'])
            ->name('install.execute')
            ->middleware('rate', ['rule' => 'install'])
            ->middleware('admin.write');
    });
})->middleware([app\http\install\middleware\InstallMiddleware::class, 'session']);
