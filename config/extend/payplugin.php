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
    'price' => NULL,
    'describe' => '支持微信个人收款码、赞赏码、经营码、商家码收款，监听回调',
    'website' => 'https://weixin.qq.com',
    'helplink' => '',
    'version' => '1.0',
    'state' => 1,
  ),
  1 => 
  array (
    'platform' => 'sqbpay',
    'name' => '收钱吧',
    'class_name' => 'ShouQianBa',
    'price' => NULL,
    'describe' => '主流移动支付全能收 信用卡,花呗都能用,生意帮手收钱吧,移动收款就用它!',
    'website' => 'https://www.shouqianba.com',
    'helplink' => '',
    'version' => '1.0',
    'state' => 1,
  ),
  2 => 
  array (
    'platform' => 'alipay',
    'name' => '支付宝',
    'class_name' => 'AliPay',
    'price' => NULL,
    'describe' => '支持支付宝个人收款码、经营码收款，监听回调',
    'website' => 'https://www.alipay.com',
    'helplink' => '',
    'version' => '1.0',
    'state' => 1,
  ),
);
