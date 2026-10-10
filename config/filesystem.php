<?php
declare(strict_types=1);

return [
    // 默认磁盘
    'default' => 'local',
    // 磁盘列表
    'disks'   => [
        'local'  => [
            'type' => 'local',
            'root' => app()->getRuntimePath() . 'storage',
        ],
        'public' => [
            // 收款图片固定采用本地磁盘；通过文件服务重编码后写入，不能直接暴露原上传文件。
            'type'       => 'local',
            // 磁盘路径；站点配置须禁止此目录脚本执行，图片路径只由服务器随机生成。
            'root'       => app()->getRootPath() . 'public/storage',
            // 磁盘路径对应的外部URL路径
            'url'        => '/storage',
            // 可见性
            'visibility' => 'public',
        ],
        // 更多的磁盘配置信息
    ],
];
