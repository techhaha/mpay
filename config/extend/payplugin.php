<?php
// +----------------------------------------------------------------------
// | 支付插件列表
// +----------------------------------------------------------------------

return array(
  0 =>
  array(
    'platform' => 'wxpay',
    'name' => '微信支付',
    'class_name' => 'WxPay',
    'price' => '0.00',
    'describe' => '支持微信个人收款码、赞赏码、经营码、商家码收款，监听回调',
    'website' => 'https://weixin.qq.com/',
    'state' => 1,
  ),
  1 =>
  array(
    'platform' => 'alipay',
    'name' => '支付宝',
    'class_name' => 'AliPay',
    'price' => '0.00',
    'describe' => '支持支付宝个人收款码、经营码收款，监听回调',
    'website' => 'https://www.alipay.com/',
    'state' => 1,
  ),
  2 =>
  array(
    'platform' => 'sqbpay',
    'name' => '收钱吧',
    'class_name' => 'ShouQianBa',
    'price' => '0.00',
    'describe' => '主流移动支付全能收 信用卡,花呗都能用,生意帮手收钱吧,移动收款就用它!',
    'website' => 'https://www.shouqianba.com/',
    'state' => 1,
  ),
  3 =>
  array(
    'platform' => 'alipayb',
    'name' => '支付宝账单',
    'class_name' => 'AliPayb',
    'price' => '99.00',
    'describe' => '支付宝账单查询回调，免挂机，稳定不掉线',
    'website' => 'https://open.alipay.com/',
    'state' => 1,
  ),
);
