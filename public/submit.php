<?php

// [ 应用入口文件 ]
namespace think;

require __DIR__ . '/../vendor/autoload.php';

// 执行HTTP应用并响应
$app = new App();
$http = $app->http;

$app->route->rule('','/PayController/submit');
$response = $http->run();

$response->send();

$http->end($response);
