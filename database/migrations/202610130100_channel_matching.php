<?php
declare(strict_types=1);

// 新装和明确升级共用；保留旧迁移摘要，执行器核验订单快照后才退役预约表。
return ['ma_pay_order_channel_matching' => ['table' => 'ma_pay_order', 'channel_matching' => true]];
