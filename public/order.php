<?php

header('content-type: application/json; charset=utf-8');
$path = '../runtime/order.json';
if (!file_exists($path)) {
    exit('{"code":3,"msg":"文件不存在"}');
} else {
    exit(file_get_contents($path));
}
