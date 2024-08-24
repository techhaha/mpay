<?php
header('content-type: application/json; charset=utf-8');
$order = file_get_contents('../runtime/order.json');
echo $order;
