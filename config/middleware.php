<?php
declare(strict_types=1);
// 中间件配置
return [
    // 别名或分组
    'alias'    => [
        'session' => think\middleware\SessionInit::class,
        // 后台组先 session，再 admin.auth；写路由增加来源/载荷和 CSRF，登录也执行 admin.write。
        'admin.auth' => app\http\admin\middleware\AdminAuthMiddleware::class,
        'admin.write' => [app\http\admin\middleware\AdminWriteMiddleware::class,
            app\http\middleware\CsrfMiddleware::class],
        // 使用原生中间件参数，只有配置组采用较大 JSON 上限；认证、来源和 CSRF 顺序不变。
        'admin.configuration.write' => [[app\http\admin\middleware\AdminWriteMiddleware::class, ['configuration']],
            app\http\middleware\CsrfMiddleware::class],
        // 单图上传使用 multipart；仅改变载荷合同，继续执行同源和 Session CSRF。
        'admin.image.write' => [[app\http\admin\middleware\AdminWriteMiddleware::class, ['image']],
            app\http\middleware\CsrfMiddleware::class],
        // 顺序由组声明：先加载会话，再校验当前访问来源/JSON 边界，最后核对 CSRF。
        'cashier.write' => [think\middleware\SessionInit::class,
            app\http\api\middleware\CashierOriginMiddleware::class, app\http\middleware\CsrfMiddleware::class],
    ],
    // 优先级设置，此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [],
];
