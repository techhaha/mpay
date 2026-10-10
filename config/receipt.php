<?php
declare(strict_types=1);

// 核心执行预算是工程边界，不代表任何真实平台已经支持这些查询区间或容量。
return [
    'lease_seconds' => 45,
    'max_pages' => 20,
    'max_events' => 5000,
    // 每轮处理一个窗口，重叠部分通过稳定事件键去重；只有全部分页落库才推进完成时间。
    'window_seconds' => 300,
    'overlap_seconds' => 60,
    'initial_lookback_seconds' => 300,
    'maximum_history_seconds' => 86400,
    // 留出上游账务可见性的短暂延迟；更慢来源仍须按真实合同设置重叠/补查能力。
    'visibility_delay_seconds' => 3,
    'poll_interval_seconds' => 3,
    'disabled_retry_seconds' => 300,
];
