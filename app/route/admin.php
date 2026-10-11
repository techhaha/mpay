<?php
declare(strict_types=1);

use app\http\admin\controller\AdminAuthController;
use app\http\admin\controller\OperationsController;
use app\http\admin\controller\PaymentAppController;
use app\http\admin\controller\PaymentCatalogController;
use app\http\admin\controller\PaymentConfigurationController;
use app\http\admin\controller\PaymentPollGroupController;
use app\http\admin\controller\PayOrderController;
use app\http\admin\controller\RuntimeController;
use app\http\admin\controller\SystemSettingsController;
use think\facade\Route;

// Session 仅在后台组初始化；登录仍校验同源 JSON 和 CSRF，其余接口统一认证后再执行写保护。
Route::group('adminapi', function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])->name('admin.login')->middleware('admin.write');
    Route::group('', function (): void {
        Route::rule('me', [AdminAuthController::class, 'me'], 'GET|HEAD')->name('admin.me');
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('admin.logout')->middleware('admin.write');
        Route::post('password', [AdminAuthController::class, 'password'])->name('admin.password')->middleware('admin.write');
        Route::rule('dashboard', [OperationsController::class, 'dashboard'], 'GET|HEAD')->name('admin.dashboard');
        Route::rule('audit', [OperationsController::class, 'audit'], 'GET|HEAD')->name('admin.audit');
        Route::group('catalog', function (): void {
            Route::rule('', [PaymentCatalogController::class, 'index'], 'GET|HEAD')->name('admin.catalog');
            foreach (['save', 'delete', 'refresh', 'enabled'] as $action) {
                Route::post($action, [PaymentCatalogController::class, $action])
                    ->name('admin.catalog.' . $action)->middleware('admin.configuration.write');
            }
        });
        Route::group('runtime', function (): void {
            Route::rule('', [RuntimeController::class, 'overview'], 'GET|HEAD')->name('admin.runtime');
            Route::rule('collections', [RuntimeController::class, 'collections'], 'GET|HEAD')->name('admin.runtime.collections');
            Route::rule('doctor', [RuntimeController::class, 'doctor'], 'GET|HEAD')->name('admin.runtime.doctor');
        });
        // 应用与轮询组共用外层认证；全部 POST（包括预览）使用同源 JSON/CSRF 保护。
        Route::group('applications', function (): void {
            Route::rule('', [PaymentAppController::class, 'index'], 'GET|HEAD')->name('admin.applications');
            foreach (['create', 'save', 'reveal', 'rotate', 'signature', 'routing'] as $action) {
                Route::post($action, [PaymentAppController::class, $action])->name('admin.applications.' . $action)->middleware('admin.configuration.write');
            }
        });
        Route::group('poll-groups', function (): void {
            Route::rule('', [PaymentPollGroupController::class, 'index'], 'GET|HEAD')->name('admin.poll-groups');
            foreach (['create', 'save', 'bind', 'preview'] as $action) {
                Route::post($action, [PaymentPollGroupController::class, $action])->name('admin.poll-groups.' . $action)->middleware('admin.configuration.write');
            }
        });
        Route::group('settings', function (): void {
            Route::rule('', [SystemSettingsController::class, 'index'], 'GET|HEAD')->name('admin.settings');
            Route::rule('payment-defaults', [SystemSettingsController::class, 'paymentDefaults'], 'GET|HEAD')->name('admin.settings.defaults');
            Route::post('save', [SystemSettingsController::class, 'save'])->name('admin.settings.save')->middleware('admin.configuration.write');
        });
        // 配置组复用外层 Session/认证；写操作统一挂带固定正文上限的同源/CSRF 保护。
        Route::group('configuration', function (): void {
            foreach (['plugins' => 'plugins', 'detail' => 'configuration',
                'channels' => 'channels', 'channel' => 'channel', 'image' => 'image'] as $path => $action) {
                Route::rule($path, [PaymentConfigurationController::class, $action], 'GET|HEAD')->name('admin.configuration.' . $path);
            }
            Route::post('image-upload', [PaymentConfigurationController::class, 'uploadImage'])
                ->name('admin.configuration.image-upload')->middleware('admin.image.write');
            Route::group('', function (): void {
                foreach (['channel-test' => 'testChannel', 'editor-create' => 'editorCreate', 'editor-save' => 'editorSave', 'plugin-save' => 'pluginSave', 'editor-enabled' => 'editorEnabled', 'channel-delete' => 'deleteChannel'] as $path => $action) {
                    Route::post($path, [PaymentConfigurationController::class, $action])->name('admin.configuration.' . $path);
                }
            })->middleware('admin.configuration.write');
        });
        Route::group('receipts', function (): void {
            foreach (['list' => 'receipts', 'detail' => 'receipt', 'history' => 'receiptHistory',
                'ingress' => 'ingress', 'ingress-flows' => 'ingressFlows'] as $path => $action) {
                Route::rule($path, [OperationsController::class, $action], 'GET|HEAD')->name('admin.receipts.' . $path);
            }
        });
        Route::group('notifications', function (): void {
            foreach (['list' => 'notifications', 'detail' => 'notification', 'attempts' => 'notificationAttempts'] as $path => $action) {
                Route::rule($path, [OperationsController::class, $action], 'GET|HEAD')->name('admin.notifications.' . $path);
            }
        });
        Route::group('orders', function (): void {
            foreach (['list' => 'index', 'detail' => 'detail', 'history' => 'history'] as $path => $action) {
                Route::rule($path, [PayOrderController::class, $action], 'GET|HEAD')->name('admin.orders.' . $path);
            }
            Route::get('export', [PayOrderController::class, 'export'])->name('admin.orders.export');
            Route::post('confirm', [PayOrderController::class, 'confirm'])->name('admin.orders.confirm')->middleware('admin.write');
            Route::post('close', [PayOrderController::class, 'close'])->name('admin.orders.close')->middleware('admin.write');
            Route::post('retry-notification', [PayOrderController::class, 'retryNotification'])->name('admin.orders.retry')->middleware('admin.write');
        });
    })->middleware('admin.auth');
})->middleware('session');
