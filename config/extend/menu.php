<?php
// +----------------------------------------------------------------------
// | 后台菜单配置
// +----------------------------------------------------------------------

return [
  [
    'id' => 'console',
    'title' => '平台首页',
    'icon' => 'icon pear-icon pear-icon-home',
    'type' => 1,
    'openType' => '_iframe',
    'href' => 'Console/console',
  ],
  [
    'id' => 'order',
    'title' => '订单管理',
    'icon' => 'icon pear-icon pear-icon-survey',
    'type' => 1,
    'openType' => '_iframe',
    'href' => '/Order/index',
  ],
  [
    'id' => 'payManage',
    'title' => '账号管理',
    'icon' => 'icon pear-icon pear-icon-security',
    'type' => 1,
    'openType' => '_iframe',
    'href' => '/PayManage/index',
  ],
  [
    'id' => 'pluginManage',
    'title' => '插件管理',
    'icon' => 'icon pear-icon pear-icon-modular',
    'type' => 1,
    'openType' => '_iframe',
    'href' => '/Plugin/index',
  ],
  [
    'id' => 'userCenter',
    'title' => '用户中心',
    'icon' => 'icon pear-icon pear-icon-user',
    'type' => 1,
    'openType' => '_iframe',
    'href' => '/User/index',
  ],
  // [
  //   'id' => 'system',
  //   'title' => '系统设置',
  //   'icon' => 'icon pear-icon pear-icon-import',
  //   'type' => 1,
  //   'openType' => '_iframe',
  //   'href' => '/System/index',
  // ],
  // [
  //   'id' => 'pay',
  //   'title' => '支付管理',
  //   'icon' => 'icon pear-icon pear-icon-import',
  //   'type' => 0,
  //   'href' => '',
  //   'children' =>    [
  //     [
  //       'id' => 'pay_qrcode_list',
  //       'title' => '收款账户',
  //       'icon' => 'icon pear-icon pear-icon-import',
  //       'type' => 1,
  //       'openType' => '_iframe',
  //       'href' => '/PayQrcode/index',
  //     ],
  //   ],
  // ],
];
