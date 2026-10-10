<?php
declare(strict_types=1);

// MPAY 部署信息和工程默认值。通道规则需由相应用例校验并冻结，配置值不是验收结论。
return [
    // 环境标识不授予测试库操作权限；测试入口另行检查显式测试配置。
    'environment' => env('MPAY_ENV', 'production'),
    // 本站地址由当前 HTTP 访问生成，多域名及迁移无需配置固定 site_url。
    // 通用业务单截止上限；插件付款窗口及金额偏移仅由各自 schema 声明。
    'order_valid_seconds' => 300,
];
