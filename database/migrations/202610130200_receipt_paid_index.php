<?php
declare(strict_types=1);

// 到账日统计先按付款日期定位，再用支付号核对应用归属；保留既有流水与迁移摘要。
return ['ma_receipt_flow_paid_index' => ['table' => 'ma_receipt_flow', 'receipt_paid_index' => true]];
