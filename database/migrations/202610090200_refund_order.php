<?php
declare(strict_types=1);

// 原单锁串行化退款额度；默认请求与商户退款号均由 pay_no/request_key 唯一约束防重。
// 平台请求在事务外执行，PROCESSING/UNKNOWN 不因租约或超时自动释放、重发。
return ['ma_refund_order' => 'CREATE TABLE ma_refund_order (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    refund_no VARCHAR(20) NOT NULL,
    pay_no VARBINARY(64) NOT NULL,
    request_key VARBINARY(73) NOT NULL,
    refund_amount BIGINT UNSIGNED NOT NULL,
    status TINYINT UNSIGNED NOT NULL DEFAULT 0,
    channel_refund_no VARBINARY(128) NULL,
    result_code VARCHAR(64) NOT NULL DEFAULT \'refund_processing\',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_refund_no (refund_no),
    UNIQUE KEY uk_refund_request (pay_no,request_key),
    CONSTRAINT fk_refund_pay FOREIGN KEY (pay_no) REFERENCES ma_pay_order (pay_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC'];
