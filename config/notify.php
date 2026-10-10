<?php
declare(strict_types=1);

// 自动通知策略，以任务首次创建为期限起点；调整配置不改旧任务目标、参数或签名。
return [
    // 首次立即发送，后续七个间隔，单位秒；总计最多八次持久派发意图（含崩溃/发送前拒绝）。
    'retry_delays_seconds' => [10, 30, 120, 600, 1800, 7200, 21600],
    'automatic_deadline_seconds' => 86400,
    // HTTP 总超时必须至少比租约短五秒，为提交结果留余量；DNS 后还要再次核对截止时间。
    'lease_seconds' => 30,
];
