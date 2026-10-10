<?php
declare(strict_types=1);
// +----------------------------------------------------------------------
// | 路由设置
// +----------------------------------------------------------------------

return [
    // pathinfo分隔符
    'pathinfo_depr'         => '/',
    // 是否开启路由延迟解析
    'url_lazy_route'        => false,
    // 强制显式路由，未知路径不能回退为自动控制器/方法调用。
    'url_route_must'        => true,
    // 按声明区分路径大小写，避免同一入口存在未经声明的别名。
    'url_case_sensitive'    => true,
    // 自动扫描子目录分组
    'route_auto_group'      => false,
    // 合并路由规则
    'route_rule_merge'      => false,
    // 完整匹配路径，禁止 /adminapi/foundation/extra 命中较短的已知路由。
    'route_complete_match'  => true,
    // 去除斜杠
    'remove_slash'          => false,
    // 默认的路由变量规则
    'default_route_pattern' => '[\w\.]+',
    // false 才保留完整路径；空字符串在 ThinkPHP 中会剥离任意后缀，导致 .php 协议入口失配。
    'url_html_suffix'       => false,
    // 访问控制器层名称
    'controller_layer'      => 'controller',
    // 空控制器名
    'empty_controller'      => 'Error',
    // 是否使用控制器后缀
    'controller_suffix'     => false,
    // 默认模块名（开启自动多模块有效）
    'default_module'        => 'index',
    // 默认控制器名
    'default_controller'    => 'Index',
    // 默认操作名
    'default_action'        => 'index',
    // 操作方法后缀
    'action_suffix'         => '',
    // 非路由变量是否使用普通参数方式（用于URL生成）
    'url_common_param'      => true,
    // 操作方法的参数绑定方式 route get param
    'action_bind_param'     => 'get',
    // 请求缓存规则 true为自动规则
    'request_cache_key'     => true,
    // 请求缓存有效期
    'request_cache_expire'  => null,
    // 全局请求缓存排除规则
    'request_cache_except'  => [],
    // 请求缓存的Tag
    'request_cache_tag'     => '',
    // API版本header变量
    'api_version'           => 'Api-Version',
];
