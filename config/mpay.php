<?php
declare(strict_types=1);

// MPAY 部署信息和工程默认值。通道规则需由相应用例校验并冻结，配置值不是验收结论。
return [
    // 环境标识，来自 MPAY_ENV，默认 production；开发/隔离联调使用 development/testing。
    // 影响开发网络例外等工程行为，不授予测试库操作权限，也不会自动启用隔离测试入口。
    'environment' => env('MPAY_ENV', 'production'),

    // 本站地址由当前 HTTP 访问生成，多域名及迁移无需配置固定 site_url。
    // 业务单有效期的工程默认值，整数秒；后台已保存的“付款默认设置”优先。
    // 新单冻结期限，改默认值不重算旧单；插件可在原期限内缩短付款窗口，金额偏移仍由插件定义。
    'order_valid_seconds' => 300,
];
