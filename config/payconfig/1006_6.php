<?php
// +----------------------------------------------------------------------
// | 支付监听配置，一个文件，一个账号
// +----------------------------------------------------------------------

return [
    // 用户账号配置
    'user' => [
        'pid'       =>  1006,
        'key'       =>  ''
    ],
    // 收款平台账号配置
    'pay' => [
        // 账号id
        'aid'       =>  6,
        // 收款平台
        'platform'  =>  'storepay',
        // 插件类名
        'payclass'  =>  'ZhiHuiJingYing',
        // 账号
        'account'   =>  '18239931385',
        // 密码
        'password'  =>  '12345678',
        // 订单查询参数配置
        'query'     =>  array (
  'pageNo' => 1,
  'pageSize' => 10,
  'payClient' => 4,
  'status' => 2,
  '_t' => NULL,
  'createTime_begin' => NULL,
  'createTime_end' => NULL,
),
    ]
];
