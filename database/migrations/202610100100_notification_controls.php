<?php
declare(strict_types=1);

/** 通知调度与历史应答分开保存；追加可空时间列，不改原任务、签名内容或发送记录。 */
return [
    'ma_notify_task_first_ack_at' => [
        'table' => 'ma_notify_task', 'column' => 'first_ack_at',
        'sql' => 'ALTER TABLE ma_notify_task ADD COLUMN first_ack_at DATETIME NULL DEFAULT NULL',
    ],
    'ma_notify_task_released_at' => [
        'table' => 'ma_notify_task', 'column' => 'released_at',
        'sql' => 'ALTER TABLE ma_notify_task ADD COLUMN released_at DATETIME NULL DEFAULT NULL',
    ],
];
