<?php
// +----------------------------------------------------------------------
// | 支付插件列表
// +----------------------------------------------------------------------

return array (
  0 => 
  array (
    'platform' => 'wxpay',
    'name' => '微信支付',
    'class_name' => 'WxPay',
    'price' => '0.00',
    'describe' => '支持微信个人收款码、赞赏码、经营码、商家码收款，监听回调',
    'website' => 'https://weixin.qq.com/',
    'state' => 1,
    'query' => 
    array (
    ),
  ),
  1 => 
  array (
    'platform' => 'alipay',
    'name' => '支付宝',
    'class_name' => 'AliPay',
    'price' => '0.00',
    'describe' => '支持支付宝个人收款码、经营码收款，监听回调',
    'website' => 'https://www.alipay.com/',
    'state' => 1,
    'query' => 
    array (
    ),
  ),
  2 => 
  array (
    'platform' => 'sqbpay',
    'name' => '收钱吧',
    'class_name' => 'ShouQianBa',
    'price' => '99.00',
    'describe' => '主流移动支付全能收 信用卡,花呗都能用,生意帮手收钱吧,移动收款就用它!',
    'website' => 'https://www.shouqianba.com/',
    'state' => 1,
    'query' => 
    array (
      'date_end' => NULL,
      'date_start' => NULL,
      'page' => 1,
      'page_size' => 10,
      'upayQueryType' => 0,
      'status' => '2000',
      'store_sn' => '',
      'type' => '30',
    ),
  ),
  3 => 
  array (
    'platform' => 'storepay',
    'name' => '数字门店',
    'class_name' => 'ZhiHuiJingYing',
    'price' => '99.00',
    'describe' => '数字门店',
    'website' => 'https://store.zhihuijingyingba.com/',
    'state' => 1,
    'query' => 
    array (
      'pageNo' => 1,
      'pageSize' => 10,
      'payClient' => 4,
      'status' => 2,
      '_t' => NULL,
      'createTime_begin' => NULL,
      'createTime_end' => NULL,
    ),
  ),
  4 => 
  array (
    'platform' => 'ysepay',
    'name' => '小Y经营',
    'class_name' => 'YsePay',
    'price' => '99.00',
    'describe' => '为商户和消费者提供安全、便捷、高效的支付产品与服务助力商户提升运营效率，实现数字化运营',
    'website' => 'https://xym.ysepay.com/',
    'state' => 1,
    'query' => 
    array (
      'storeNo' => '',
      'bizType' => 3,
      'payType' => '',
      'orderStatus' => 3,
      'trmNo' => '',
      'operatorUser' => '',
      'codeBoardCode' => '',
      'pageSize' => 10,
      'pageNo' => 1,
      'orderNo' => '',
    ),
  ),
  5 => 
  array (
    'platform' => 'mqpay',
    'name' => '码钱',
    'class_name' => 'MaQian',
    'price' => '99.00',
    'describe' => '码钱商管平台',
    'website' => 'https://m.hkrt.cn/',
    'state' => 1,
    'query' => 
    array (
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
  ),
  6 => 
  array (
    'platform' => 'lklpay',
    'name' => '拉卡拉',
    'class_name' => 'LaKaLa',
    'price' => '99.00',
    'describe' => '数字支付，更安全，更高效',
    'website' => 'https://customer.lakala.com/',
    'state' => 0,
    'query' => 
    array (
      'requestTime' => NULL,
      'systemCode' => 'MERDASH',
      'version' => '1.0',
      'openEntity' => NULL,
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
      'merchantNos' => NULL,
      'merInnerNos' => NULL,
    ),
  ),
  7 => 
  array (
    'platform' => 'sftpay',
    'name' => '盛付通',
    'class_name' => 'ShengPay',
    'price' => '99.00',
    'describe' => '轻松生活 放心支付',
    'website' => 'https://b.shengpay.com/',
    'state' => 0,
    'query' => 
    array (
    ),
  ),
);
