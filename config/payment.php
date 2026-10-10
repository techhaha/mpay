<?php
declare(strict_types=1);

// 插件从 app/common/payment 固定目录发现，数据库目录记录不能作为任意类执行入口。
return [
    // 显式 CLI 测试或已校验实例的 cli-server/testing 可用；正式 FPM 和环境变量不能开启模拟能力。
    'test_plugins' => [],
    // 执行认领有效期，秒。首版仅支持本地静态码准备，执行租约到期后复用原识别材料恢复。
    'execution_lease_seconds' => 30,
];
