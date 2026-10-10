<?php
declare(strict_types=1);

// +----------------------------------------------------------------------
// | 数据库设置：Web、CLI 和 ORM 共用同一连接合同，实际凭证只从本机环境读取。
// +----------------------------------------------------------------------
// Env::get(name) 会把 on/false 等文本转换为布尔；连接字符串取已解析的原始值，保留密码字节。
$databaseEnvironment = app()->env->get();
// 缺少 pdo_mysql 时仍可打开安装环境检查，不在配置加载阶段访问不存在的扩展常量。
$mysqlOptions = defined('PDO::MYSQL_ATTR_INIT_COMMAND') ? [
    constant('PDO::MYSQL_ATTR_INIT_COMMAND') => "SET time_zone = '+08:00', SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'",
] : [];
return [
    // 默认连接名，对应 connections 下的 mysql；不是任意数据库名。
    'default' => 'mysql',
    // ORM 保存时使用日期时间字段，统一采用业务约定的创建/更新时间名称。
    'auto_timestamp' => 'datetime',
    'datetime_format' => 'Y-m-d H:i:s',
    'datetime_field' => 'created_at,updated_at',
    // 数据库连接定义；默认不提供用户名和密码，避免错误配置时自动尝试 root。
    'connections' => [
        'mysql' => [
            // 采用 MySQL 驱动，不能仅替换环境变量就宣称其他数据库兼容。
            'type' => 'mysql',
            // 数据库服务器地址，可与 Web 服务器分开部署。
            'hostname' => $databaseEnvironment['DB_HOST'] ?? env('DB_HOST', '127.0.0.1'),
            // 专用业务库名；隔离测试不使用此默认值。
            'database' => $databaseEnvironment['DB_NAME'] ?? env('DB_NAME', ''),
            // 本应用数据库账号及密码，真实值只保存在本机环境配置中。
            'username' => $databaseEnvironment['DB_USER'] ?? env('DB_USER', ''),
            'password' => $databaseEnvironment['DB_PASS'] ?? env('DB_PASS', ''),
            // MySQL TCP 端口，隔离开发实例可通过 DB_PORT 与其他应用分开。
            'hostport' => env('DB_PORT', '3306'),
            // 字符集与迁移一致，支持中文和完整 Unicode。
            'charset' => 'utf8mb4',
            // 用于 Db::name 等按逻辑表名的查询；模型的显式 table 已包含此前缀。
            'prefix' => 'ma_',
            'params' => [
                // 使用驱动预处理；约束失败通过异常交由事务边界处理。
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // 扩展可用时，新连接统一到 +08:00，并用严格模式拒绝隐式截断。
            ] + $mysqlOptions,
            // 查询构造器严格检查字段，防止拼错列名后被静默忽略。
            'fields_strict' => true,
            // 不在连接中断后自动重放语句；支付事务是否重试必须由服务明确判断。
            'break_reconnect' => false,
            // SQL 与绑定参数可能包含凭证；调试模式也不记录完整查询。
            'trigger_sql' => false,
            // 不持久缓存结构，避免迁移后仍使用旧列信息；模型本身已提供显式 schema。
            'fields_cache' => false,
        ],
    ],
];
