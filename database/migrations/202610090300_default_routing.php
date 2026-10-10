<?php
declare(strict_types=1);

/** 默认组指针不依赖名称；应用模式与已有组、成员及绑定复用，不增加第二套路由表。 */
return [
    'ma_payment_type_default_poll_group_id' => [
        'table' => 'ma_payment_type', 'column' => 'default_poll_group_id',
        'sql' => 'ALTER TABLE ma_payment_type ADD COLUMN default_poll_group_id BIGINT UNSIGNED NULL DEFAULT NULL',
    ],
    'ma_payment_app_use_default_route' => [
        'table' => 'ma_payment_app', 'column' => 'use_default_route',
        'sql' => 'ALTER TABLE ma_payment_app ADD COLUMN use_default_route TINYINT UNSIGNED NOT NULL DEFAULT 1',
    ],
];
