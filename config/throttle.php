<?php
declare(strict_types=1);

// HTTP 入口与认证后额度共用配置。频率采用官方滑动窗口，身份只能来自服务器连接或已验证上下文。
return [
    'rules' => [
        'captcha' => '20/m',
        'login' => '10/m',
        'admin_ip' => '600/m',
        'admin_read' => '300/m',
        'admin_write' => '60/m',
        'admin_sensitive' => '10/m',
        'admin_expensive' => '5/m',
        'epay_ip' => '600/m',
        'epay_create' => '120/m',
        'epay_query' => '300/m',
        'epay_refund' => '10/m',
        'cashier_ip' => '600/m',
        'cashier_read' => '60/m',
        'cashier_write' => '10/m',
        'channel_notify' => '300/m',
        'install' => '5/m',
    ],
];
