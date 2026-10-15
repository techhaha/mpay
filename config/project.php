<?php
declare(strict_types=1);

// 项目展示资料集中维护。作者及服务信息仅传给已认证的关于页，不传入公开首页。
return [
    'name' => '码支付 V1',
    'tagline' => '让个人收款更加轻松便捷',
    'description' => '将收款码、到账识别、业务订单与付款通知连接起来，为你的业务网站提供统一的收款入口。',
    'links' => [
        'docs' => 'https://gitee.com/technical-laohu/mpay/wikis/Home',
        'integration' => 'https://gitee.com/technical-laohu/mpay/wikis/V1-15-业务网站接入',
        'installation' => 'https://gitee.com/technical-laohu/mpay/wikis/V1-03-安装与环境要求',
        'faq' => 'https://gitee.com/technical-laohu/mpay/wikis/V1-20-常见问题与运行排查',
        'github' => 'https://github.com/techhaha/mpay/tree/v1',
        'gitee' => 'https://gitee.com/technical-laohu/mpay/tree/v1',
        'issues' => 'https://github.com/techhaha/mpay/issues',
    ],
    'author' => ['name' => '技术老胡', 'wechat' => 'K103516'],
    'services' => [
        ['title' => '支付系统定制开发', 'description' => '围绕实际业务流程，定制收款系统、业务接入与后台管理功能。', 'icon' => 'rmb'],
        ['title' => '支付插件开发', 'description' => '根据目标平台与接口资料，开发收款接入、流水采集及到账识别插件。', 'icon' => 'component'],
        ['title' => '小程序商城定制开发', 'description' => '支持单商户、多商户及多门店业务场景，按需定制商城功能与运营流程。', 'icon' => 'cart-simple'],
    ],
    'cases' => ['代销商城', '智慧农业', '支付服务商城', '微商社区', '跨境代购'],
];
