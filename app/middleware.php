<?php
declare(strict_types=1);

// 全局中间件定义：统一处理会话协议、请求关联和响应头。
// SessionInit 按页面/后台路由装配，后台 CSRF 也由写路由显式添加，不能覆盖易支付或设备合同。
return [app\http\middleware\RequestMiddleware::class];
