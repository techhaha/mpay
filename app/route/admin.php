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

// 匿名认证入口先限制 IP，再创建 Session；验证码只开放此固定入口，不接受配置名称参数。
Route::post('adminapi/login', [AdminAuthController::class, 'login'])
    ->name('admin.login')
    ->middleware('rate', ['rule' => 'login'])
    ->middleware(['session', 'admin.write']);
Route::get('adminapi/captcha', [AdminAuthController::class, 'captcha'])
    ->name('admin.captcha')
    ->middleware('rate', ['rule' => 'captcha'])
    ->middleware('session');

// 后台 API 的 IP 粗限流先于 Session/认证；管理员额度在身份核验后执行。
Route::group('adminapi', function (): void {
    Route::group('', function (): void {
        Route::rule('me', [AdminAuthController::class, 'me'], 'GET|HEAD')->name('admin.me');
        Route::post('logout', [AdminAuthController::class, 'logout'])
            ->name('admin.logout')
            ->middleware('admin.write');
        Route::post('password', [AdminAuthController::class, 'password'])
            ->name('admin.password')
            ->middleware('rate', [
                'rule' => 'admin_sensitive',
                'identity' => 'admin',
                'action' => 'password',
            ])
            ->middleware('admin.write');
        Route::rule('dashboard', [OperationsController::class, 'dashboard'], 'GET|HEAD')->name(
            'admin.dashboard',
        );
        Route::rule('audit', [OperationsController::class, 'audit'], 'GET|HEAD')->name(
            'admin.audit',
        );
        Route::group('catalog', function (): void {
            Route::rule('', [PaymentCatalogController::class, 'index'], 'GET|HEAD')->name(
                'admin.catalog',
            );
            Route::post('save', [PaymentCatalogController::class, 'save'])
                ->name('admin.catalog.save')
                ->middleware('admin.configuration.write');
            Route::post('delete', [PaymentCatalogController::class, 'delete'])
                ->name('admin.catalog.delete')
                ->middleware('admin.configuration.write');
            Route::post('refresh', [PaymentCatalogController::class, 'refresh'])
                ->name('admin.catalog.refresh')
                ->middleware('rate', [
                    'rule' => 'admin_expensive',
                    'identity' => 'admin',
                    'action' => 'catalog_refresh',
                ])
                ->middleware('admin.configuration.write');
            Route::post('enabled', [PaymentCatalogController::class, 'enabled'])
                ->name('admin.catalog.enabled')
                ->middleware('admin.configuration.write');
        });
        // URL 避开 runtime 目录保护规则；原 Session、管理员认证及只读方法限制保持一致。
        Route::group('maintenance', function (): void {
            Route::rule('', [RuntimeController::class, 'overview'], 'GET|HEAD')->name(
                'admin.maintenance',
            );
            Route::rule('collections', [RuntimeController::class, 'collections'], 'GET|HEAD')->name(
                'admin.maintenance.collections',
            );
            Route::rule('doctor', [RuntimeController::class, 'doctor'], 'GET|HEAD')
                ->name('admin.maintenance.doctor')
                ->middleware('rate', [
                    'rule' => 'admin_expensive',
                    'identity' => 'admin',
                    'action' => 'doctor',
                ]);
        });
        // 应用与轮询组共用外层认证；全部 POST（包括预览）使用同源 JSON/CSRF 保护。
        Route::group('applications', function (): void {
            Route::rule('', [PaymentAppController::class, 'index'], 'GET|HEAD')->name(
                'admin.applications',
            );
            Route::post('create', [PaymentAppController::class, 'create'])
                ->name('admin.applications.create')
                ->middleware('admin.configuration.write');
            Route::post('save', [PaymentAppController::class, 'save'])
                ->name('admin.applications.save')
                ->middleware('admin.configuration.write');
            Route::post('reveal', [PaymentAppController::class, 'reveal'])
                ->name('admin.applications.reveal')
                ->middleware('rate', [
                    'rule' => 'admin_sensitive',
                    'identity' => 'admin',
                    'action' => 'key_reveal',
                ])
                ->middleware('admin.configuration.write');
            Route::post('rotate', [PaymentAppController::class, 'rotate'])
                ->name('admin.applications.rotate')
                ->middleware('rate', [
                    'rule' => 'admin_sensitive',
                    'identity' => 'admin',
                    'action' => 'key_rotate',
                ])
                ->middleware('admin.configuration.write');
            Route::post('signature', [PaymentAppController::class, 'signature'])
                ->name('admin.applications.signature')
                ->middleware('admin.configuration.write');
            Route::post('routing', [PaymentAppController::class, 'routing'])
                ->name('admin.applications.routing')
                ->middleware('admin.configuration.write');
        });
        Route::group('poll-groups', function (): void {
            Route::rule('', [PaymentPollGroupController::class, 'index'], 'GET|HEAD')->name(
                'admin.poll-groups',
            );
            Route::post('create', [PaymentPollGroupController::class, 'create'])
                ->name('admin.poll-groups.create')
                ->middleware('admin.configuration.write');
            Route::post('save', [PaymentPollGroupController::class, 'save'])
                ->name('admin.poll-groups.save')
                ->middleware('admin.configuration.write');
            Route::post('bind', [PaymentPollGroupController::class, 'bind'])
                ->name('admin.poll-groups.bind')
                ->middleware('admin.configuration.write');
            Route::post('preview', [PaymentPollGroupController::class, 'preview'])
                ->name('admin.poll-groups.preview')
                ->middleware('admin.configuration.write');
        });
        Route::group('settings', function (): void {
            Route::rule('', [SystemSettingsController::class, 'index'], 'GET|HEAD')->name(
                'admin.settings',
            );
            Route::rule(
                'payment-defaults',
                [SystemSettingsController::class, 'paymentDefaults'],
                'GET|HEAD',
            )->name('admin.settings.defaults');
            Route::post('save', [SystemSettingsController::class, 'save'])
                ->name('admin.settings.save')
                ->middleware('admin.configuration.write');
        });
        // 配置组复用外层 Session/认证；写操作统一挂带固定正文上限的同源/CSRF 保护。
        Route::group('configuration', function (): void {
            Route::rule(
                'plugins',
                [PaymentConfigurationController::class, 'plugins'],
                'GET|HEAD',
            )->name('admin.configuration.plugins');
            Route::rule(
                'detail',
                [PaymentConfigurationController::class, 'configuration'],
                'GET|HEAD',
            )->name('admin.configuration.detail');
            Route::rule(
                'channels',
                [PaymentConfigurationController::class, 'channels'],
                'GET|HEAD',
            )->name('admin.configuration.channels');
            Route::rule(
                'channel',
                [PaymentConfigurationController::class, 'channel'],
                'GET|HEAD',
            )->name('admin.configuration.channel');
            Route::rule(
                'image',
                [PaymentConfigurationController::class, 'image'],
                'GET|HEAD',
            )->name('admin.configuration.image');
            Route::post('image-upload', [PaymentConfigurationController::class, 'uploadImage'])
                ->name('admin.configuration.image-upload')
                ->middleware('admin.image.write');
            Route::group('', function (): void {
                Route::post('channel-test', [
                    PaymentConfigurationController::class,
                    'testChannel',
                ])->name('admin.configuration.channel-test');
                Route::post('editor-create', [
                    PaymentConfigurationController::class,
                    'editorCreate',
                ])->name('admin.configuration.editor-create');
                Route::post('editor-save', [
                    PaymentConfigurationController::class,
                    'editorSave',
                ])->name('admin.configuration.editor-save');
                Route::post('plugin-save', [
                    PaymentConfigurationController::class,
                    'pluginSave',
                ])->name('admin.configuration.plugin-save');
                Route::post('editor-enabled', [
                    PaymentConfigurationController::class,
                    'editorEnabled',
                ])->name('admin.configuration.editor-enabled');
                Route::post('channel-delete', [
                    PaymentConfigurationController::class,
                    'deleteChannel',
                ])->name('admin.configuration.channel-delete');
            })->middleware('admin.configuration.write');
        });
        Route::group('callbacks', function (): void {
            Route::rule('list', [OperationsController::class, 'callbacks'], 'GET|HEAD')->name(
                'admin.callbacks.list',
            );
            Route::rule('detail', [OperationsController::class, 'callback'], 'GET|HEAD')->name(
                'admin.callbacks.detail',
            );
        });
        Route::group('notifications', function (): void {
            Route::rule('list', [OperationsController::class, 'notifications'], 'GET|HEAD')->name(
                'admin.notifications.list',
            );
            Route::rule('detail', [OperationsController::class, 'notification'], 'GET|HEAD')->name(
                'admin.notifications.detail',
            );
            Route::rule(
                'attempts',
                [OperationsController::class, 'notificationAttempts'],
                'GET|HEAD',
            )->name('admin.notifications.attempts');
        });
        Route::group('orders', function (): void {
            Route::rule('list', [PayOrderController::class, 'index'], 'GET|HEAD')->name(
                'admin.orders.list',
            );
            Route::rule('detail', [PayOrderController::class, 'detail'], 'GET|HEAD')->name(
                'admin.orders.detail',
            );
            Route::rule('history', [PayOrderController::class, 'history'], 'GET|HEAD')->name(
                'admin.orders.history',
            );
            Route::get('export', [PayOrderController::class, 'export'])
                ->name('admin.orders.export')
                ->middleware('rate', [
                    'rule' => 'admin_expensive',
                    'identity' => 'admin',
                    'action' => 'export',
                ]);
            Route::post('confirm', [PayOrderController::class, 'confirm'])
                ->name('admin.orders.confirm')
                ->middleware('rate', [
                    'rule' => 'admin_sensitive',
                    'identity' => 'admin',
                    'action' => 'confirm',
                ])
                ->middleware('admin.write');
            Route::post('close', [PayOrderController::class, 'close'])
                ->name('admin.orders.close')
                ->middleware('admin.write');
            Route::post('refund', [PayOrderController::class, 'refund'])
                ->name('admin.orders.refund')
                ->middleware('rate', [
                    'rule' => 'admin_sensitive',
                    'identity' => 'admin',
                    'action' => 'refund',
                ])
                ->middleware('admin.write');
            Route::post('retry-notification', [PayOrderController::class, 'retryNotification'])
                ->name('admin.orders.retry')
                ->middleware('rate', [
                    'rule' => 'admin_sensitive',
                    'identity' => 'admin',
                    'action' => 'retry_notification',
                ])
                ->middleware('admin.write');
        });
    })
        ->middleware('admin.auth')
        ->middleware('rate', ['rule' => 'admin', 'identity' => 'admin']);
})
    ->middleware('rate', ['rule' => 'admin_ip'])
    ->middleware('session');
