<?php
// +----------------------------------------------------------------------
// | 支付插件列表
// +----------------------------------------------------------------------

return array (
  0 => 
  array (
    'platform' => 'sqbpay',
    'name' => '收钱吧',
    'class_name' => 'ShouQianBa',
    'price' => '99.00',
    'describe' => '主流移动支付全能收 信用卡,花呗都能用,生意帮手收钱吧,移动收款就用它!',
    'website' => 'https://www.shouqianba.com/',
    'state' => 1,
    'query' => 'a:8:{s:8:"date_end";N;s:10:"date_start";N;s:4:"page";i:1;s:9:"page_size";i:10;s:13:"upayQueryType";i:0;s:6:"status";s:4:"2000";s:8:"store_sn";s:0:"";s:4:"type";s:2:"30";}',
  ),
  1 => 
  array (
    'platform' => 'storepay',
    'name' => '数字门店',
    'class_name' => 'ZhiHuiJingYing',
    'price' => '99.00',
    'describe' => '数字门店',
    'website' => 'https://store.zhihuijingyingba.com/',
    'state' => 1,
    'query' => 'a:7:{s:6:"pageNo";i:1;s:8:"pageSize";i:10;s:9:"payClient";i:4;s:6:"status";i:2;s:2:"_t";N;s:16:"createTime_begin";N;s:14:"createTime_end";N;}',
  ),
  2 => 
  array (
    'platform' => 'ysepay',
    'name' => '小Y经营',
    'class_name' => 'Ysepay',
    'price' => '99.00',
    'describe' => '为商户和消费者提供安全、便捷、高效的支付产品与服务助力商户提升运营效率，实现数字化运营',
    'website' => 'https://xym.ysepay.com/',
    'state' => 1,
    'query' => 'a:10:{s:7:"storeNo";s:0:"";s:7:"bizType";i:3;s:7:"payType";s:0:"";s:11:"orderStatus";i:3;s:5:"trmNo";s:0:"";s:12:"operatorUser";s:0:"";s:13:"codeBoardCode";s:0:"";s:8:"pageSize";i:10;s:6:"pageNo";i:1;s:7:"orderNo";s:0:"";}',
  ),
  3 => 
  array (
    'platform' => 'mqpay',
    'name' => '码钱',
    'class_name' => 'MaQian',
    'price' => '99.00',
    'describe' => '码钱商管平台',
    'website' => 'https://m.hkrt.cn/',
    'state' => 0,
    'query' => 'a:12:{s:12:"terminalType";s:0:"";s:7:"payType";s:0:"";s:7:"payMode";s:0:"";s:11:"tradeStatus";s:1:"1";s:7:"tradeNo";s:0:"";s:7:"storeId";s:0:"";s:4:"page";i:1;s:4:"rows";i:10;s:7:"endDate";N;s:7:"endTime";N;s:9:"startDate";N;s:9:"startTime";N;}',
  ),
  4 => 
  array (
    'platform' => 'lklpay',
    'name' => '拉卡拉',
    'class_name' => 'LaKaLa',
    'price' => '99.00',
    'describe' => '数字支付，更安全，更高效',
    'website' => 'https://customer.lakala.com/',
    'state' => 0,
    'query' => '',
  ),
  5 => 
  array (
    'platform' => 'sftpay',
    'name' => '盛付通',
    'class_name' => 'ShengPay',
    'price' => '99.00',
    'describe' => '轻松生活 放心支付',
    'website' => 'https://b.shengpay.com/',
    'state' => 0,
    'query' => '',
  ),
  6 => 
  array (
    'platform' => 'haopay',
    'name' => '好支付',
    'class_name' => 'Haopay',
    'price' => 99,
    'describe' => '好支付',
    'website' => 'https://store.zhihuijingyingba.com/',
    'state' => 0,
    'query' => 
    array (
    ),
  ),
);
