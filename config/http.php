<?php
declare(strict_types=1);

// 通知连接预算与开发网段例外；应用服务显式传入允许主机，旧通知沿冻结目标发送。
// 开发内网例外只有 development/testing 环境生效，生产始终忽略该例外。
return [
    'development_hosts' => array_values(
        array_filter(array_map('trim', explode(',', (string) env('MPAY_HTTP_DEV_HOSTS', '')))),
    ),
    'development_ports' => array_map(
        'intval',
        array_values(
            array_filter(array_map('trim', explode(',', (string) env('MPAY_HTTP_DEV_PORTS', '')))),
        ),
    ),
    // 通知 HTTP 传输限制，秒和正文解码后字节；另有 16 KiB 响应头上限，不在交易事务内执行网络。
    'connect_timeout_seconds' => 3,
    'timeout_seconds' => 10,
    'response_max_bytes' => 65536,
];
