<?php
declare(strict_types=1);

// +----------------------------------------------------------------------
// | Cookie 设置：供框架 Session 等使用，敏感身份只保存在服务端。
// +----------------------------------------------------------------------
return [
    // Cookie 保存时间，单位秒；0 使用会话 Cookie。
    'expire' => 0,
    // 在本站所有路径可用，不把后台和收银台拆成互不相通的 Cookie 路径。
    'path' => '/',
    // 空域名采用当前主机范围，不向其他子域共享会话标识。
    'domain' => '',
    // 允许 HTTP 和 HTTPS 共用当前主机的会话 Cookie；请求入口不按协议改写配置。
    'secure' => false,
    // 禁止页面脚本读取会话 Cookie；CSRF token 通过模板单独提供。
    'httponly' => true,
    // 由框架响应正常写入 Set-Cookie 头。
    'setcookie' => true,
    // 限制跨站携带；写请求仍须使用 CSRF 校验，不能只依赖 SameSite。
    'samesite' => 'Lax',
];
