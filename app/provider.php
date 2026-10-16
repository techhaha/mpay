<?php
use app\ExceptionHandle;
use app\Request;

// 容器 Provider 定义：使用本项目 Request 和异常处理器覆盖框架入口绑定。
// ExceptionHandle 负责公开协议的错误输出，避免框架默认诊断页进入 API 或支付协议。
return [
    'think\Request' => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,
];
