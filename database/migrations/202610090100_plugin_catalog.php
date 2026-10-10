<?php
declare(strict_types=1);

// 插件目录保存本地元信息与新单开关，完整保存插件定义；实际装配只信任固定代码目录中的接口实现。
return ['ma_payment_plugin' => 'CREATE TABLE ma_payment_plugin (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    version VARCHAR(32) NOT NULL,
    pay_types JSON NOT NULL,
    class_name VARCHAR(255) NOT NULL,
    config_schema JSON NOT NULL,
    description TEXT NOT NULL,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uk_plugin_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'];
