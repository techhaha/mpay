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
        'aid'       =>  7,
        // 收款平台
        'platform'  =>  'lklpay',
        // 插件类名
        'payclass'  =>  'LaKaLa',
        // 账号
        'account'   =>  '13822254817',
        // 密码
        'password'  =>  'n8omf1FNqK+Irq9IlOPZJA==',
        // 订单查询参数配置
        'query'     =>  array (
  'requestTime' => NULL,
  'systemCode' => 'MERDASH',
  'version' => '1.0',
  'openEntity' => '822581058121GYW',
  'requestId' => NULL,
  'pageSize' => 10,
  'pageNum' => 1,
  'startTime' => NULL,
  'timeOption' => NULL,
  'tranSts' => 'SUCCESS',
  'orderNo' => NULL,
  'srefno' => NULL,
  'ornNo' => NULL,
  'endTime' => NULL,
  'startDate' => NULL,
  'endDate' => NULL,
  'page' => 1,
  'size' => 10,
  'merchantNos' => '822581058121GYW',
  'merInnerNos' => '4002022071722760372',
),
    ]
];
