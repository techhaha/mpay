<?php
// +----------------------------------------------------------------------
// | 支付监听配置，一个文件，一个账号
// +----------------------------------------------------------------------
return [
    // 用户账号配置
    'user' => [
        'pid'       =>  1001,
        'key'       =>  '953c4d682d9ab148277b76a06e215ce7'
    ],
    // 收款平台账号配置
    'pay' => [
        // 账号id
        'aid'       =>  40,
        // 收款平台
        'platform'  =>  'mqpay',
        // 收款平台
        'payclass'  =>  'MaQian',
        // 账号
        'account'   =>  '18657945333',
        // 密码
        'password'  =>  'Aa12345678',
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
