<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

// 短期访问保护优先现有 tmpfs；配置加载只判断环境，不建目录或写选择标记。
// Web/CLI 应使用相同文件系统视图和 PHP 目录权限；实际写入失败不另切换缓存。
// 回退目录与默认 cache 同级，避免清空普通缓存时连带删除验证码和限流记录。
$accessCachePath = dirname(__DIR__) . '/runtime/access/';
$tmpfsMounts = PHP_OS_FAMILY === 'Linux' ? @file_get_contents('/proc/self/mountinfo') : false;
if (
    is_string($tmpfsMounts) &&
    preg_match('~^\S+ \S+ \S+ \S+ /dev/shm .* - tmpfs ~m', $tmpfsMounts) &&
    @is_writable('/dev/shm')
) {
    $accessCachePath =
        '/dev/shm/mpay-' . substr(hash('sha256', dirname(__DIR__)), 0, 24) . '/access/';
}

return [
    // 默认缓存驱动
    'default' => 'file',

    // 缓存连接方式配置
    'stores' => [
        // 限流计数和验证码摘要由框架直接读写和过期；不扩展或替换官方 File 驱动。
        'access' => [
            'type' => 'File',
            'path' => $accessCachePath,
            'prefix' => '',
            'expire' => 120,
        ],
        // 平台状态单独保存，CLI/Web 共用站点目录；插件根据真实认证状态刷新。
        'receipt' => [
            'type' => 'File',
            'path' => dirname(__DIR__) . '/runtime/receipt/',
            // File 驱动把 prefix 作为子目录；独立 path 已完成隔离，不使用含冒号的目录名。
            'prefix' => '',
            'expire' => 0,
        ],
        'file' => [
            // 驱动方式
            'type' => 'File',
            // 缓存保存目录
            'path' => '',
            // 缓存前缀
            'prefix' => '',
            // 缓存有效期 0表示永久缓存
            'expire' => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize' => [],
        ],
        // 更多的缓存连接
    ],
];
