<?php
declare(strict_types=1);

// 保留已登记的安装基线摘要，独立追加通道删除标记；历史订单、快照和流水均不删除。
return [
    'ma_payment_channel_deleted_at' => [
        'table' => 'ma_payment_channel',
        'column' => 'deleted_at',
        'sql' => 'ALTER TABLE ma_payment_channel ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL',
    ],
];
