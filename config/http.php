<?php
declare(strict_types=1);

// 商户通知的出站限制及开发内网例外；不是对所有插件 SDK 统一设置公网限制。
// 例外只在 mpay.environment 为 development/testing 时生效，生产环境忽略；修改后重启 work。
return [
    // 开发例外主机列表，来自 MPAY_HTTP_DEV_HOSTS，逗号分隔，例如 localhost,127.0.0.1。
    // 只写主机名或 IP，不写协议、路径和端口；空列表不开例外，必须同时命中下方例外端口。
    'development_hosts' => array_values(
        array_filter(array_map('trim', explode(',', (string) env('MPAY_HTTP_DEV_HOSTS', '')))),
    ),
    // 开发例外端口列表，来自 MPAY_HTTP_DEV_PORTS，逗号分隔，例如 8000,8767；空列表不开例外。
    // 仅限定开发内网放行；正常公网通知可使用合法端口 1～65535，无须在此登记。
    'development_ports' => array_map(
        'intval',
        array_values(
            array_filter(array_map('trim', explode(',', (string) env('MPAY_HTTP_DEV_PORTS', '')))),
        ),
    ),
    // 商户通知建立连接超时，整数秒，允许 1～30；仍受本次派发绝对截止时间限制。
    'connect_timeout_seconds' => 3,

    // 商户通知单次 HTTP 总超时，整数秒，允许 1～60，包含连接及传输。
    // notify.lease_seconds 必须至少比它多 5 秒；插件 SDK 的单次请求超时由 send/SDK 自身控制。
    'timeout_seconds' => 10,

    // 商户通知响应正文解码后的最大字节数，65536 即 64 KiB，允许 1～1048576。
    // 超限按请求异常处理；后台仅保留最多 4096 字节诊断片段，完整正文参与正常 ACK 判断。
    // 响应头另有 16 KiB 上限；此项不是插件 SDK 的响应上限，也不是后台日志保存长度。
    'response_max_bytes' => 65536,
];
