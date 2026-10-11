<?php
declare(strict_types=1);

/** 所有订单统一应用归属和生命周期；删除独立测试分类，保留已执行迁移的原始摘要。 */
return [
    'ma_biz_order_remove_test_fields' => ['table' => 'ma_biz_order', 'drop_columns' => ['order_scene', 'test_request_key']],
    'ma_biz_order_comments' => ['table' => 'ma_biz_order', 'comments' => [
        'comment' => '业务订单：保存原始收款需求、应用归属、访问凭证及最终业务状态',
        'columns' => [
            'app_id' => '接入应用ID，所有订单均由建单服务关联有效应用',
            'pid' => '下单时冻结的应用PID，不随应用后续修改而变化',
            'credential_version_id' => '下单时冻结的应用签名密钥版本ID，供原订单通知和查询核对',
            'notify_url' => '下单时冻结的异步通知地址；NULL表示未提供目标，不生成通知任务',
        ],
    ]],
    'ma_pay_order_comments' => ['table' => 'ma_pay_order', 'comments' => [
        'comment' => '支付订单：一次实际支付尝试、冻结通道配置、执行进度及到账证据',
        'columns' => [
            'poll_group_id' => '选路时冻结的轮询组ID；直接指定通道时为NULL',
            'route_mode' => '支付选取方式：0直接指定通道，1顺序轮询，2权重随机，3默认优先',
        ],
    ]],
];
