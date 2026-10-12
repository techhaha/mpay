<?php
declare(strict_types=1);

/** 内部监听预算；登录状态永久缓存，以下期限不代表平台凭证有效期。 */
return [
    'scan_interval_seconds' => 3,
    'query_interval_seconds' => 3,
    'late_check_seconds' => 300,
    'order_page_size' => 500,
    'max_orders_per_plugin' => 10000,
    'account_budget_seconds' => 15,
    'plugin_budget_seconds' => 30,
    'prelogin_interval_seconds' => 600,
];
