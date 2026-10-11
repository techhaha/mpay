<?php
declare(strict_types=1);

/** 后台指定通道测试复用真实订单；只追加用途和幂等键、放宽外部业务关联，不改历史数据。 */
return [
    'ma_biz_order_order_scene' => ['table' => 'ma_biz_order', 'column' => 'order_scene',
        'sql' => 'ALTER TABLE ma_biz_order ADD COLUMN order_scene TINYINT UNSIGNED NOT NULL DEFAULT 0'],
    'ma_biz_order_test_request_key' => ['table' => 'ma_biz_order', 'column' => 'test_request_key',
        'sql' => 'ALTER TABLE ma_biz_order ADD COLUMN test_request_key CHAR(32) NULL DEFAULT NULL UNIQUE'],
    'ma_biz_order_nullable' => ['table' => 'ma_biz_order', 'nullable' => ['app_id', 'pid', 'credential_version_id', 'notify_url']],
    'ma_pay_order_nullable' => ['table' => 'ma_pay_order', 'nullable' => ['poll_group_id']],
    'ma_biz_order_comments' => ['table' => 'ma_biz_order', 'comments' => [
        'comment' => '业务订单：保存原始收款需求、应用归属、访问凭证及最终业务状态',
        'columns' => [
            'order_scene' => '订单用途：0业务收款，1后台通道真实付款测试；默认运营统计仅含0',
            'test_request_key' => '后台通道测试请求的32位随机幂等键，唯一；普通业务单为NULL，不作为访问凭证',
            'app_id' => '接入应用ID；后台通道测试为NULL，业务收款必须指定应用',
            'pid' => '下单时冻结的应用PID；后台通道测试为NULL',
            'credential_version_id' => '下单时冻结的应用签名密钥版本ID；后台通道测试为NULL',
            'notify_url' => '下单时冻结的商户异步通知地址；后台通道测试为NULL且不建立通知任务',
        ],
    ]],
    'ma_pay_order_comments' => ['table' => 'ma_pay_order', 'comments' => [
        'comment' => '支付订单：一次实际支付尝试、冻结通道配置、执行进度及到账证据',
        'columns' => [
            'poll_group_id' => '选路时冻结的轮询组ID；后台直接指定通道测试为NULL',
            'route_mode' => '支付选取方式：0后台指定通道，1顺序轮询，2权重随机，3默认优先',
        ],
    ]],
];
