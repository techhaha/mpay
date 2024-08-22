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
        'aid'       =>  1,
        // 收款平台
        'platform'  =>  'sqbpay',
        // 账号
        'account'   =>  '188*****423',
        // 密码
        'password'  =>  '76********QB',
        // 订单查询参数配置
        'query'     =>  array(
            'date_end' => NULL,
            'date_start' => NULL,
            'page' => 1,
            'page_size' => 10,
            'upayQueryType' => 0,
            'status' => '2000',
            'store_sn' => '',
            'type' => '30',
        ),
    ]
];
