<?php
declare(strict_types=1);
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 命令名到入口类的显式映射；由框架容器装配服务，不在配置加载时执行命令。
    'commands' => [
        'system:migrate' => app\command\SystemMigrateCommand::class,
        // 迁移和业务初始化分开；仅对已核对结构且无业务数据的独立库创建管理员与 API 凭证。
        'system:initialize' => app\command\SystemInitializeCommand::class,
        // 私有 JSON 输入，管理范围/账号/通道共用服务与事务审计；不会跳过真实来源验证。
        'payment:configuration' => app\command\PaymentConfigurationCommand::class,
        // 外部守护只需这两个前台命令；采集网络等待不会占用通知/维护进程。
        'payment:work' => app\command\PaymentWorkCommand::class,
        'payment:watch' => app\command\PaymentWatchCommand::class,
        'system:doctor' => app\command\SystemDoctorCommand::class,
        'system:prune' => app\command\SystemPruneCommand::class,
    ],
];
