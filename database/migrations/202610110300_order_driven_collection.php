<?php
declare(strict_types=1);

/** 退役配置驱动采集结构；执行器限定本版本和操作，已有交易/配置不自动回填或清空。 */
return [
    'ma_payment_plugin_conf_collection_cleanup' => ['table' => 'ma_payment_plugin_conf', 'collection_cleanup' => true],
];
