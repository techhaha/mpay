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
    'platform' => 'ysepay',
    'name' => '小Y经营',
    'class_name' => 'YsePay',
    'price' => NULL,
    'describe' => '为商户和消费者提供安全、便捷、高效的支付产品与服务助力商户提升运营效率，实现数字化运营',
    'website' => 'https://xym.ysepay.com',
    'helplink' => '',
    'version' => '1.0',
    'state' => 1,
  ),
);
