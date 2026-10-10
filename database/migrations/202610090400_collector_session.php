<?php
declare(strict_types=1);

// 登录会话独立于查询游标；租约覆盖登录、切换门店及整窗查询，避免同账号相互挤占。
return ['ma_receipt_session' => 'CREATE TABLE ma_receipt_session (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_key VARBINARY(64) NOT NULL,
    auth_fingerprint VARBINARY(64) NOT NULL,
    session_json JSON NULL,
    claim_token VARBINARY(64) NULL,
    lease_until DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uk_receipt_session (session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'];
