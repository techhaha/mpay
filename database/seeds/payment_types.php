<?php
declare(strict_types=1);

/**
 * 新装支付方式及图片种子。图片是随程序交付的 PNG，初始化时登记为普通本地图片资产。
 * 仅安装入口读取本文件；后台和收银台只读取支付方式保存的图片引用。
 */
return [
    ['code' => 'alipay', 'name' => '支付宝', 'enabled' => 1, 'icon' => 'alipay-icon.png', 'logo' => 'alipay-logo.png'],
    ['code' => 'wxpay', 'name' => '微信支付', 'enabled' => 1, 'icon' => 'wechat-pay-icon.png', 'logo' => 'wechat-pay-logo.png'],
    ['code' => 'unionpay', 'name' => '云闪付', 'enabled' => 0, 'icon' => 'cloudpay-icon.png', 'logo' => 'cloudpay-logo.png'],
    ['code' => 'bank', 'name' => '银行卡转账', 'enabled' => 1, 'icon' => 'bank-icon.png', 'logo' => 'bank-logo.png'],
];
