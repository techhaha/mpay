<?php
declare(strict_types=1);

// +----------------------------------------------------------------------
// | 会话设置：仅供 HTTP 身份及 CSRF 使用，不保存订单、通知或流水事实。
// +----------------------------------------------------------------------
return [
    // 会话 Cookie 名，与同主机其他应用的默认 PHPSESSID 区分。
    'name' => 'mpay_session',
    // 不允许通过 URL/表单参数指定 Session ID，统一使用框架 Cookie。
    'var_session_id' => '',
    // 单机私有文件驱动，路径由框架 runtime 决定，不能放进 public。
    'type' => 'file',
    // 仅使用 cache 驱动时选择缓存连接；file 驱动不依赖 Redis。
    'store' => null,
    // 会话有效期，单位秒；此值不作为交易过期时间。
    'expire' => 1800,
    // 框架会话数据使用的前缀。
    'prefix' => 'mpay',
];
