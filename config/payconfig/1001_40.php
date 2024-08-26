<?php
// +----------------------------------------------------------------------
// | 支付监听配置，一个文件，一个账号
// +----------------------------------------------------------------------
return [
    // 用户账号配置
    'user' => [
        'pid'       =>  1001,
        'key'       =>  '7ImzF6Rf8OciQcmRJv8oTNBwIp6uqF0p'
    ],
    // 收款平台账号配置
    'pay' => [
        // 账号id
        'aid'       =>  40,
        // 收款平台
        'platform'  =>  'mqpay',
        // 账号
        'account'   =>  '258000000',
        // 密码
        'password'  =>  '123456',
        // 订单查询参数配置
        'query'     =>  array (
  'terminalType' => '',
  'payType' => '',
  'payMode' => '',
  'tradeStatus' => '1',
  'tradeNo' => '',
  'storeId' => '',
  'page' => 1,
  'rows' => 10,
  'endDate' => NULL,
  'endTime' => NULL,
  'startDate' => NULL,
  'startTime' => NULL,
),
    ]
];
