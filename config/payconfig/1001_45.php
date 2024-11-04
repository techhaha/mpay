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
        'aid'       =>  45,
        // 收款平台
        'platform'  =>  'storepay',
        // 插件类名
        'payclass'  =>  'ZhiHuiJingYing',
        // 账号
        'account'   =>  '16546465',
        // 密码
        'password'  =>  '5464564654',
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
