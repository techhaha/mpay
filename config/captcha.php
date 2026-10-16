<?php
declare(strict_types=1);

// 图片与验证由官方组件提供；登录服务补充两分钟期限，使用原生缓存取出删除，不增加并发锁。
return [
    'length' => 4,
    'codeSet' => '2346789ACDEFGHJKMNPQRTUVWXY',
    'expire' => 120,
    'fontSize' => 20,
    'imageW' => 150,
    'imageH' => 40,
    'useCurve' => true,
    'useNoise' => false,
    'bg' => [248, 250, 252],
    // API 模式可能返回答案，登录入口只能输出图片。
    'api' => false,
];
