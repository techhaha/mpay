<?php
declare(strict_types=1);

/**
 * MPAY 全新安装基线。表、字段、索引、外键及注释直接定义最终结构。
 * 按外键依赖建表；不包含历史升级、演示数据或站点凭证。
 */
return [
    'ma_admin_operation_log' => <<<'SQL'
CREATE TABLE ma_admin_operation_log (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `admin_user_id` bigint(20) unsigned DEFAULT NULL COMMENT '操作管理员ID；CLI等无管理员上下文时为NULL',
  `request_id` varbinary(64) NOT NULL COMMENT 'HTTP请求或CLI操作关联编号',
  `action` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '管理动作编码，如通道保存、插件刷新、应用更新',
  `target_type` varchar(64) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '操作对象类型编码；NULL表示没有特定对象',
  `target_id` varchar(64) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '操作对象ID或编码，按target_type解释；无特定对象时为NULL',
  `result_code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '管理动作结果编码，如created、updated、enabled；非支付状态',
  `summary_json` json DEFAULT NULL COMMENT '允许字段组成的脱敏操作摘要；秘密值仅记录已更新等标记',
  `client_ip` varchar(45) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '管理员请求来源IP；CLI等无来源地址时为NULL',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  KEY `idx_admin_audit_created` (`created_at`,`id`),
  KEY `idx_admin_operation_created` (`admin_user_id`,`created_at`),
  KEY `idx_admin_target` (`target_type`,`target_id`,`id`),
  KEY `idx_admin_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='管理员配置、凭证及系统管理动作的脱敏审计记录'
SQL,

    'ma_admin_user' => <<<'SQL'
CREATE TABLE ma_admin_user (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `username` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '管理员登录名，全库唯一',
  `password_hash` varchar(255) COLLATE utf8mb4_bin NOT NULL COMMENT '管理员密码的单向哈希，不是密码原文',
  `session_version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '会话版本，递增后已有登录会话失效',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '账户状态：0停用，1启用',
  `singleton_slot` tinyint(4) GENERATED ALWAYS AS (1) STORED COMMENT '固定生成值1，唯一索引限制全库仅一个管理员',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`),
  UNIQUE KEY `uk_admin_singleton` (`singleton_slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='单管理员账户及登录会话失效版本'
SQL,

    'ma_channel_notify_log' => <<<'SQL'
CREATE TABLE ma_channel_notify_log (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `channel_id` bigint(20) unsigned NOT NULL COMMENT '通知入口所属通道ID，关联ma_payment_channel.id',
  `request_id` varbinary(64) NOT NULL COMMENT '通知请求关联编号，用于查技术日志',
  `event_key` varbinary(128) DEFAULT NULL COMMENT '插件解析出的来源事件标识；无法解析时为NULL',
  `plugin_code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '处理通知的插件编码',
  `result_code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '入口处理诊断码，见PaymentResultCode；接收成功不等于支付成功',
  `payload_digest` char(64) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '标准事实摘要；未形成有效标准结果时为NULL',
  `summary_json` json DEFAULT NULL COMMENT '脱敏接收摘要JSON；NULL无摘要，不存认证秘密或完整报文',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  KEY `idx_channel_ingress_created` (`created_at`,`id`),
  KEY `idx_channel_notify_created` (`channel_id`,`created_at`),
  KEY `idx_channel_ingress_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='通道通知入口的接收与校验日志，包含未能确认订单的请求'
SQL,

    'ma_file_asset' => <<<'SQL'
CREATE TABLE ma_file_asset (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `storage` varchar(16) COLLATE utf8mb4_bin NOT NULL DEFAULT 'local' COMMENT '文件存储类型，当前为local本地存储',
  `path` varchar(255) COLLATE utf8mb4_bin NOT NULL COMMENT '相对于public/storage的存储路径，全库唯一',
  `original_name` varchar(255) COLLATE utf8mb4_bin NOT NULL COMMENT '登记的安全文件名，收款图片重新编码后不保留客户端原路径',
  `mime_type` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '服务器验证后的文件MIME类型',
  `size` bigint(20) unsigned NOT NULL COMMENT '实际存储文件大小，字节',
  `sha256` char(64) COLLATE utf8mb4_bin NOT NULL COMMENT '存储文件内容SHA-256摘要',
  `admin_user_id` bigint(20) unsigned DEFAULT NULL COMMENT '上传管理员ID，关联ma_admin_user.id；NULL无管理员归属',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_path` (`path`),
  KEY `idx_file_sha256` (`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='已验证的本地图片资产及上传归属，业务表通过文件ID引用'
SQL,

    'ma_pay_callback_log' => <<<'SQL'
CREATE TABLE ma_pay_callback_log (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `pay_no` varbinary(64) DEFAULT NULL COMMENT '关联支付单号ma_pay_order.pay_no；未定位订单时可为NULL',
  `request_id` varbinary(64) NOT NULL COMMENT '请求或内部执行关联编号，用于串联技术日志',
  `event_key` varbinary(128) DEFAULT NULL COMMENT '来源事件标识；无事件或校验前可为NULL',
  `payload_digest` char(64) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '标准收款事实SHA-256摘要；未形成标准事实时为NULL',
  `result_code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '支付处理诊断码，见PaymentResultCode；不是订单状态',
  `summary_json` json DEFAULT NULL COMMENT '允许留存的脱敏处理摘要JSON；NULL无摘要，不存完整平台报文',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  KEY `idx_callback_pay_created` (`pay_no`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='支付执行和收款确认的处理日志，不等同于最终到账流水'
SQL,

    'ma_pay_order_operation_log' => <<<'SQL'
CREATE TABLE ma_pay_order_operation_log (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `pay_no` varbinary(64) NOT NULL COMMENT '被操作支付单号，关联ma_pay_order.pay_no',
  `admin_user_id` bigint(20) unsigned NOT NULL COMMENT '操作管理员ID，关联ma_admin_user.id',
  `request_id` varbinary(64) NOT NULL COMMENT '管理员请求关联编号',
  `action` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '交易动作编码，如人工确认、关闭或商户通知操作',
  `reason` varchar(512) COLLATE utf8mb4_bin NOT NULL COMMENT '管理员填写的特殊操作原因',
  `evidence_json` json DEFAULT NULL COMMENT '脱敏核对依据和操作参数JSON；NULL无附加依据',
  `before_status` tinyint(3) unsigned NOT NULL COMMENT '操作前交易状态（TradeConstant）：0待发起支付，1等待付款，2支付成功，3支付失败，4已关闭，5已超时',
  `after_status` tinyint(3) unsigned NOT NULL COMMENT '操作后交易状态（TradeConstant）：0待发起支付，1等待付款，2支付成功，3支付失败，4已关闭，5已超时',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  KEY `idx_pay_operation_created` (`pay_no`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='管理员对订单执行特殊交易动作的审计记录'
SQL,

    'ma_payment_app' => <<<'SQL'
CREATE TABLE ma_payment_app (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `pid` varbinary(32) NOT NULL COMMENT '易支付接入应用PID，全库唯一，不等同于内部自增ID',
  `name` varchar(128) COLLATE utf8mb4_bin NOT NULL COMMENT '接入应用显示名称',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '应用新单接入状态：0停用，1启用',
  `active_key_id` bigint(20) unsigned DEFAULT NULL COMMENT '当前可用于新接入的KEY版本ID，关联ma_payment_app_key.id；登记前可为NULL',
  `revision` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '应用配置修订号，用于并发修改校验',
  `allowed_hosts` json NOT NULL COMMENT '允许的商户通知及回跳域名列表JSON；空列表不允许新订单',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  `use_default_route` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '收款路由模式：0使用自定义绑定，1随支付方式自动绑定默认组',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='业务网站接入应用，独立PID、活动KEY和收款路由模式'
SQL,

    'ma_payment_plugin' => <<<'SQL'
CREATE TABLE ma_payment_plugin (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '插件唯一编码，由插件主类声明',
  `name` varchar(128) COLLATE utf8mb4_bin NOT NULL COMMENT '插件显示名称，由插件声明',
  `version` varchar(32) COLLATE utf8mb4_bin NOT NULL COMMENT '插件声明的代码版本',
  `pay_types` json NOT NULL COMMENT '插件支持的支付方式编码列表JSON',
  `class_name` varchar(255) COLLATE utf8mb4_bin NOT NULL COMMENT '已发现插件主类的完整类名，执行时仍按本地目录及接口核验',
  `config_schema` json NOT NULL COMMENT '插件配置字段定义列表JSON，含类型、校验、默认值和说明，不含真实配置值',
  `description` text COLLATE utf8mb4_bin NOT NULL COMMENT '插件自行声明的使用说明；空字符串表示不显示，不由系统补写说明',
  `enabled` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '插件新单开关：0停用，1启用；不阻止历史订单按原插件处理',
  `revision` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '目录管理修订号，用于启停的并发修改校验',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='固定目录扫描同步的插件定义与新单启停状态'
SQL,

    'ma_payment_plugin_conf' => <<<'SQL'
CREATE TABLE ma_payment_plugin_conf (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `plugin_code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '插件编码，关联本地插件定义及ma_payment_plugin.code',
  `name` varchar(128) COLLATE utf8mb4_bin NOT NULL COMMENT '插件配置显示名称',
  `config_json` json NOT NULL COMMENT '按插件config_schema保存的参数对象，含凭证原值，不得公开回显',
  `config_version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '插件参数版本，关联ma_payment_plugin_conf_revision的冻结配置',
  `enabled` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '配置执行授权：0停用或待核对，1启用；存量订单使用原快照',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  KEY `idx_conf_plugin` (`plugin_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='通道独立插件配置；平台账号分组及登录状态由插件处理'
SQL,

    'ma_payment_type' => <<<'SQL'
CREATE TABLE ma_payment_type (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `code` varchar(16) COLLATE utf8mb4_bin NOT NULL COMMENT '支付方式编码，如alipay、wxpay、unionpay，接口使用此编码',
  `name` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '支付方式显示名称',
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT '0' COMMENT '显示排序值，数值越小越靠前',
  `revision` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '配置修订号，用于拒绝覆盖并发修改',
  `icon_asset_id` bigint(20) unsigned DEFAULT NULL COMMENT '小图标文件ID，关联ma_file_asset.id；NULL时使用内置图标',
  `logo_asset_id` bigint(20) unsigned DEFAULT NULL COMMENT '带文字标识文件ID，关联ma_file_asset.id；NULL时使用内置标识',
  `enabled` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '新订单支付方式开关：0停用，1启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  `default_poll_group_id` bigint(20) unsigned DEFAULT NULL COMMENT '该支付方式的默认组ID，关联ma_payment_poll_group.id；NULL表示尚未关联',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_type_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='支付方式字典、品牌素材及默认轮询组指针'
SQL,

    'ma_system_config' => <<<'SQL'
CREATE TABLE ma_system_config (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `config_key` varchar(128) COLLATE utf8mb4_bin NOT NULL COMMENT '配置组唯一键，如payment_defaults或日志设置组',
  `value_json` json DEFAULT NULL COMMENT '该配置组的字段对象JSON；NULL为空值，须按对应配置组规则解释',
  `version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '配置组修订版本，用于拒绝覆盖并发修改',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='按配置组保存的系统设置及并发修订版本'
SQL,

    'ma_payment_app_key' => <<<'SQL'
CREATE TABLE ma_payment_app_key (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `app_id` bigint(20) unsigned NOT NULL COMMENT '所属应用ID，关联ma_payment_app.id',
  `version` int(10) unsigned NOT NULL COMMENT '应用内递增的KEY版本号',
  `secret` varchar(128) COLLATE utf8mb4_bin NOT NULL COMMENT '应用签名KEY原值，属于敏感凭证，不得公开回显或写日志',
  `key_fingerprint` char(64) COLLATE utf8mb4_bin NOT NULL COMMENT 'KEY的SHA-256指纹，用于核对凭证身份，不替代签名密钥',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_key_version` (`app_id`,`version`),
  UNIQUE KEY `uk_app_key_owner` (`app_id`,`id`),
  CONSTRAINT `fk_app_key_app` FOREIGN KEY (`app_id`) REFERENCES `ma_payment_app` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='应用KEY历史版本，重置后保留原订单的签名责任'
SQL,

    'ma_payment_plugin_conf_revision' => <<<'SQL'
CREATE TABLE ma_payment_plugin_conf_revision (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `api_config_id` bigint(20) unsigned NOT NULL COMMENT '插件配置ID，关联ma_payment_plugin_conf.id',
  `config_version` int(10) unsigned NOT NULL COMMENT '配置内版本号，与api_config_id共同唯一',
  `plugin_version` varchar(32) COLLATE utf8mb4_bin NOT NULL COMMENT '冻结时的插件代码版本标识',
  `config_json` json NOT NULL COMMENT '冻结的插件参数JSON，含凭证原值，不得公开回显',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conf_revision` (`api_config_id`,`config_version`),
  CONSTRAINT `fk_conf_revision_conf` FOREIGN KEY (`api_config_id`) REFERENCES `ma_payment_plugin_conf` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='插件参数的不可变历史版本，供订单和在途采集使用'
SQL,

    'ma_payment_poll_group' => <<<'SQL'
CREATE TABLE ma_payment_poll_group (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `name` varchar(128) COLLATE utf8mb4_bin NOT NULL COMMENT '轮询组显示名称；默认组身份不依赖名称',
  `pay_type_id` bigint(20) unsigned NOT NULL COMMENT '组内支付方式ID，关联ma_payment_type.id',
  `route_mode` tinyint(3) unsigned NOT NULL COMMENT '选路策略（RouteConstant）：1顺序轮询，2权重随机，3默认优先',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '轮询组新单开关：0停用，1启用',
  `revision` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '组配置修订号，用于并发修改校验',
  `remark` varchar(500) COLLATE utf8mb4_bin NOT NULL DEFAULT '' COMMENT '管理员填写的组备注，空字符串表示未填写',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  KEY `idx_poll_group_type` (`pay_type_id`),
  CONSTRAINT `fk_poll_group_type` FOREIGN KEY (`pay_type_id`) REFERENCES `ma_payment_type` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='同支付方式下的通道轮询组及选路策略'
SQL,

    'ma_receipt_scope' => <<<'SQL'
CREATE TABLE ma_receipt_scope (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `receipt_scope_key` varbinary(64) NOT NULL COMMENT '收款范围唯一摘要键，供订单、占用和流水关联',
  `pay_type_id` bigint(20) unsigned NOT NULL COMMENT '支付方式ID，关联ma_payment_type.id',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  `api_config_id` bigint(20) unsigned NOT NULL COMMENT '通道专属插件配置ID；业务锁范围，不代表平台账号',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_receipt_scope_key` (`receipt_scope_key`),
  UNIQUE KEY `uk_scope_configuration_type` (`api_config_id`,`pay_type_id`),
  KEY `fk_scope_type` (`pay_type_id`),
  CONSTRAINT `fk_scope_configuration` FOREIGN KEY (`api_config_id`) REFERENCES `ma_payment_plugin_conf` (`id`),
  CONSTRAINT `fk_scope_type` FOREIGN KEY (`pay_type_id`) REFERENCES `ma_payment_type` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='配置与支付方式的归属和确认锁范围；不承担匹配算法或采集账号分组'
SQL,

    'ma_biz_order' => <<<'SQL'
CREATE TABLE ma_biz_order (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `biz_no` varbinary(64) NOT NULL COMMENT '业务单号，B加14位上海创建时间加5位随机数，共20位，全库唯一',
  `trace_no` varbinary(64) NOT NULL COMMENT '支付链路内部跟踪编号，与请求request_id区别，不作为访问凭证',
  `app_id` bigint(20) unsigned DEFAULT NULL COMMENT '接入应用ID，所有订单均由建单服务关联有效应用',
  `pid` varbinary(32) DEFAULT NULL COMMENT '下单时冻结的应用PID，不随应用后续修改而变化',
  `merchant_order_no` varbinary(256) NOT NULL COMMENT '商户提交的外部订单号，在所属应用内唯一',
  `subject` varchar(256) COLLATE utf8mb4_bin NOT NULL COMMENT '商户提交的商品或订单标题',
  `order_amount` int(10) unsigned NOT NULL COMMENT '商户原始订单金额，单位分，不随识别金额偏移变化',
  `paid_amount` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '已确认支付的业务金额，单位分；未支付为0，实际到账证据另存支付单',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '交易状态（TradeConstant）：0待发起支付，1等待付款，2支付成功，3支付失败，4已关闭，5已超时',
  `active_pay_no` varbinary(64) DEFAULT NULL COMMENT '本业务单的支付单号，关联ma_pay_order.pay_no；未选择方式时为NULL',
  `attempt_count` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '已创建支付尝试数：0未创建，1已创建；当前不允许第二次尝试',
  `credential_version_id` bigint(20) unsigned DEFAULT NULL COMMENT '下单时冻结的应用签名密钥版本ID，供原订单通知和查询核对',
  `access_token_hash` char(64) COLLATE utf8mb4_bin NOT NULL COMMENT '收银台访问令牌SHA-256摘要，不是商户签名KEY',
  `client_ip` varchar(45) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '下单时记录的客户端IP；未知时为NULL',
  `expire_at` datetime NOT NULL COMMENT '业务订单有效截止时间，上海时区',
  `paid_at` datetime DEFAULT NULL COMMENT '已核实的付款时间，上海时区；尚未成功时为NULL',
  `closed_at` datetime DEFAULT NULL COMMENT '本地关闭时间，上海时区；未关闭为NULL，不代表撤销平台收款码',
  `failed_at` datetime DEFAULT NULL COMMENT '确定失败记录时间，上海时区；无确定失败时为NULL',
  `timeout_at` datetime DEFAULT NULL COMMENT '本地记录超时的时间，上海时区；未标记超时为NULL',
  `notify_url` varchar(2048) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '下单时冻结的异步通知地址；NULL表示未提供目标，不生成通知任务',
  `return_url` varchar(2048) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '下单时冻结的浏览器回跳地址；NULL表示未提供，回跳不证明付款',
  `ext_json` json DEFAULT NULL COMMENT '建单指纹、请求方式、设备、透传参数及原收银台令牌JSON，含敏感访问凭证',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_biz_no` (`biz_no`),
  UNIQUE KEY `uk_biz_access_token` (`access_token_hash`),
  UNIQUE KEY `uk_biz_merchant_order` (`app_id`,`merchant_order_no`),
  KEY `idx_biz_app_created` (`app_id`,`created_at`,`id`),
  KEY `idx_biz_app_paid` (`app_id`,`status`,`paid_at`,`id`),
  KEY `idx_biz_pid_created` (`pid`,`created_at`,`id`),
  KEY `idx_biz_paid` (`status`,`paid_at`,`id`),
  KEY `idx_biz_status_expire` (`status`,`expire_at`),
  KEY `idx_biz_credential` (`credential_version_id`),
  KEY `idx_biz_admin_created` (`created_at`,`id`),
  KEY `fk_biz_credential` (`app_id`,`credential_version_id`),
  CONSTRAINT `fk_biz_credential` FOREIGN KEY (`app_id`, `credential_version_id`) REFERENCES `ma_payment_app_key` (`app_id`, `id`),
  CONSTRAINT `fk_biz_merchant` FOREIGN KEY (`app_id`) REFERENCES `ma_payment_app` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='业务订单：保存原始收款需求、应用归属、访问凭证及最终业务状态'
SQL,

    'ma_payment_channel' => <<<'SQL'
CREATE TABLE ma_payment_channel (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `plugin_code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '当前通道的插件编码，须与所关联配置一致',
  `api_config_id` bigint(20) unsigned NOT NULL COMMENT '通道独立插件配置ID，关联ma_payment_plugin_conf.id，通道间不共用',
  `name` varchar(128) COLLATE utf8mb4_bin NOT NULL COMMENT '通道显示名称',
  `pay_types` json NOT NULL COMMENT '通道支付方式编码列表JSON，当前通道选择一种支付方式',
  `receipt_scope_key` varbinary(64) NOT NULL COMMENT '收款匹配范围键，关联ma_receipt_scope.receipt_scope_key',
  `min_amount` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '单笔最低订单金额，单位分，含边界',
  `max_amount` int(10) unsigned NOT NULL DEFAULT '999999999' COMMENT '单笔最高订单金额，单位分，含边界',
  `daily_limit_amount` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '上海自然日收款额度上限，单位分；0不限，选路同时计入有效在途占用',
  `daily_limit_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '上海自然日订单笔数上限；0不限，选路同时计入有效在途占用',
  `available_from` varchar(5) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '每日可用起始时间HH:mm，与available_to配对；NULL表示全天',
  `available_to` varchar(5) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '每日可用结束时间HH:mm，可跨午夜；与起始时间同时为NULL表示全天',
  `enabled` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '通道新单开关：0停用，1启用；不撤销历史订单收款责任',
  `current_revision_id` bigint(20) unsigned DEFAULT NULL COMMENT '当前通道快照ID，关联ma_payment_channel_revision.id；登记前可为NULL',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  `deleted_at` datetime DEFAULT NULL COMMENT '通道软删除时间，上海时区；NULL未删除，历史订单及快照保留',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channel_configuration` (`api_config_id`),
  KEY `idx_channel_scope` (`receipt_scope_key`),
  KEY `idx_channel_plugin` (`plugin_code`),
  CONSTRAINT `fk_channel_conf` FOREIGN KEY (`api_config_id`) REFERENCES `ma_payment_plugin_conf` (`id`),
  CONSTRAINT `fk_channel_scope` FOREIGN KEY (`receipt_scope_key`) REFERENCES `ma_receipt_scope` (`receipt_scope_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='收款通道、独立插件配置关联及通用选路限制'
SQL,

    'ma_payment_poll_group_bind' => <<<'SQL'
CREATE TABLE ma_payment_poll_group_bind (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `app_id` bigint(20) unsigned NOT NULL COMMENT '接入应用ID，关联ma_payment_app.id',
  `pay_type_id` bigint(20) unsigned NOT NULL COMMENT '支付方式ID，关联ma_payment_type.id；同应用每种方式仅一条绑定',
  `poll_group_id` bigint(20) unsigned NOT NULL COMMENT '所绑定轮询组ID，关联ma_payment_poll_group.id',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '绑定新单开关：0停用，1启用',
  `cursor` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '此应用及支付方式的顺序轮询游标，仅新支付尝试提交时推进',
  `revision` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '路由绑定修订号，用于并发修改校验',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_pay_type` (`app_id`,`pay_type_id`),
  KEY `idx_poll_bind_type` (`pay_type_id`),
  KEY `idx_poll_bind_group` (`poll_group_id`),
  CONSTRAINT `fk_poll_bind_app` FOREIGN KEY (`app_id`) REFERENCES `ma_payment_app` (`id`),
  CONSTRAINT `fk_poll_bind_group` FOREIGN KEY (`poll_group_id`) REFERENCES `ma_payment_poll_group` (`id`),
  CONSTRAINT `fk_poll_bind_type` FOREIGN KEY (`pay_type_id`) REFERENCES `ma_payment_type` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='应用按支付方式绑定轮询组，并维护各自顺序轮询游标'
SQL,

    'ma_payment_channel_revision' => <<<'SQL'
CREATE TABLE ma_payment_channel_revision (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `channel_id` bigint(20) unsigned NOT NULL COMMENT '通道ID，关联ma_payment_channel.id',
  `revision` int(10) unsigned NOT NULL COMMENT '通道内递增的快照版本，与channel_id共同唯一',
  `api_config_id` bigint(20) unsigned NOT NULL COMMENT '本快照的插件配置ID，关联ma_payment_plugin_conf.id',
  `config_version` int(10) unsigned NOT NULL COMMENT '本快照使用的插件配置版本，不随当前配置变化',
  `snapshot_json` json NOT NULL COMMENT '冻结的通道与插件参数JSON，可能含凭证，不得公开回显',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channel_revision` (`channel_id`,`revision`),
  KEY `idx_revision_conf` (`api_config_id`),
  CONSTRAINT `fk_revision_channel` FOREIGN KEY (`channel_id`) REFERENCES `ma_payment_channel` (`id`),
  CONSTRAINT `fk_revision_conf` FOREIGN KEY (`api_config_id`) REFERENCES `ma_payment_plugin_conf` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='通道历史快照，供原订单按创建时的接入配置继续执行'
SQL,

    'ma_payment_poll_group_channel' => <<<'SQL'
CREATE TABLE ma_payment_poll_group_channel (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `poll_group_id` bigint(20) unsigned NOT NULL COMMENT '轮询组ID，关联ma_payment_poll_group.id',
  `channel_id` bigint(20) unsigned NOT NULL COMMENT '成员通道ID，关联ma_payment_channel.id；同组不得重复',
  `sort_no` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '成员排序值，越小越靠前；不表示被选中的概率',
  `weight` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '权重随机策略下的相对权重，越大被选中概率越高',
  `is_default` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '默认优先策略中的优先成员：0否，1是',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '成员参与选路开关：0不参与，1参与；仍需满足通道自身限制',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_poll_group_channel` (`poll_group_id`,`channel_id`),
  KEY `idx_poll_member_channel` (`channel_id`),
  CONSTRAINT `fk_poll_member_channel` FOREIGN KEY (`channel_id`) REFERENCES `ma_payment_channel` (`id`),
  CONSTRAINT `fk_poll_member_group` FOREIGN KEY (`poll_group_id`) REFERENCES `ma_payment_poll_group` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='轮询组成员及组内排序、概率权重和默认优先标记'
SQL,

    'ma_pay_order' => <<<'SQL'
CREATE TABLE ma_pay_order (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `pay_no` varbinary(64) NOT NULL COMMENT '支付单号，P加14位上海创建时间加5位随机数，共20位；对外trade_no',
  `biz_no` varbinary(64) NOT NULL COMMENT '所属业务单号，关联ma_biz_order.biz_no',
  `trace_no` varbinary(64) NOT NULL COMMENT '从业务单继承的内部链路跟踪编号',
  `attempt_no` tinyint(4) GENERATED ALWAYS AS (1) STORED COMMENT '固定生成值1，配合业务单唯一索引限制仅一次支付尝试',
  `poll_group_id` bigint(20) unsigned DEFAULT NULL COMMENT '选路时冻结的轮询组ID；直接指定通道时为NULL',
  `route_mode` tinyint(3) unsigned NOT NULL COMMENT '支付选取方式：0直接指定通道，1顺序轮询，2权重随机，3默认优先',
  `channel_id` bigint(20) unsigned NOT NULL COMMENT '实际收款通道ID，关联ma_payment_channel.id',
  `channel_revision_id` bigint(20) unsigned NOT NULL COMMENT '下单冻结的通道快照ID，关联ma_payment_channel_revision.id',
  `pay_type_id` bigint(20) unsigned NOT NULL COMMENT '本次支付方式ID，关联ma_payment_type.id',
  `plugin_code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '本次冻结的插件编码，不随当前通道改插件而变化',
  `pay_amount` int(10) unsigned NOT NULL COMMENT '支付金额，单位分；收款准备后可为识别金额，确认成功后恢复原金额',
  `receipt_scope_key` varbinary(64) NOT NULL COMMENT '收款匹配范围键，关联ma_receipt_scope.receipt_scope_key',
  `receipt_flow_id` bigint(20) unsigned DEFAULT NULL COMMENT '最终绑定到账流水ID，关联ma_receipt_flow.id；未绑定或仅人工确认时可为NULL',
  `confirmation_source` tinyint(3) unsigned DEFAULT NULL COMMENT '收款确认依据（TradeConstant）：1插件确认，2人工确认；NULL尚未确认',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '交易状态（TradeConstant）：0待发起支付，1等待付款，2支付成功，3支付失败，4已关闭，5已超时',
  `channel_order_no` varbinary(128) DEFAULT NULL COMMENT '插件或平台受理订单号；平台未提供时为NULL',
  `channel_trade_no` varbinary(128) DEFAULT NULL COMMENT '插件确认的交易编号，可能为平台流水号或监听合成编号；未提供时为NULL',
  `request_at` datetime DEFAULT NULL COMMENT '首次认领插件支付执行时间，上海时区；尚未执行时为NULL',
  `expire_at` datetime NOT NULL COMMENT '该支付尝试的有效截止时间，上海时区',
  `paid_at` datetime DEFAULT NULL COMMENT '已核实的付款时间，上海时区；未成功为NULL',
  `closed_at` datetime DEFAULT NULL COMMENT '支付单本地关闭时间，上海时区；未关闭为NULL',
  `failed_at` datetime DEFAULT NULL COMMENT '支付单确定失败记录时间，上海时区；未失败为NULL',
  `timeout_at` datetime DEFAULT NULL COMMENT '支付单本地超时记录时间，上海时区；未标记为NULL',
  `ext_json` json DEFAULT NULL COMMENT '支付执行上下文及personal_receipt JSON，含原金额、识别金额、实收证据和付款材料',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pay_no` (`pay_no`),
  UNIQUE KEY `uk_pay_biz_attempt` (`biz_no`,`attempt_no`),
  UNIQUE KEY `uk_pay_flow` (`receipt_flow_id`),
  UNIQUE KEY `uk_pay_plugin_trade` (`plugin_code`,`channel_trade_no`),
  KEY `idx_pay_poll_group` (`poll_group_id`),
  KEY `idx_pay_paid` (`status`,`paid_at`,`id`),
  KEY `idx_pay_status_expire` (`status`,`expire_at`),
  KEY `idx_pay_scope_status_created` (`receipt_scope_key`,`status`,`created_at`),
  KEY `idx_pay_channel_created` (`channel_id`,`created_at`,`status`),
  KEY `idx_pay_revision` (`channel_revision_id`),
  KEY `idx_pay_type` (`pay_type_id`),
  KEY `idx_pay_collection` (`plugin_code`,`status`,`expire_at`,`id`),
  KEY `idx_pay_channel_active` (`channel_id`,`status`,`expire_at`,`id`),
  KEY `idx_pay_channel_receipt` (`channel_id`,`pay_amount`,`expire_at`,`id`),
  CONSTRAINT `fk_pay_biz` FOREIGN KEY (`biz_no`) REFERENCES `ma_biz_order` (`biz_no`),
  CONSTRAINT `fk_pay_channel` FOREIGN KEY (`channel_id`) REFERENCES `ma_payment_channel` (`id`),
  CONSTRAINT `fk_pay_poll_group` FOREIGN KEY (`poll_group_id`) REFERENCES `ma_payment_poll_group` (`id`),
  CONSTRAINT `fk_pay_revision` FOREIGN KEY (`channel_revision_id`) REFERENCES `ma_payment_channel_revision` (`id`),
  CONSTRAINT `fk_pay_scope` FOREIGN KEY (`receipt_scope_key`) REFERENCES `ma_receipt_scope` (`receipt_scope_key`),
  CONSTRAINT `fk_pay_type` FOREIGN KEY (`pay_type_id`) REFERENCES `ma_payment_type` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='支付订单：一次实际支付尝试、冻结通道配置、执行进度及到账证据'
SQL,

    'ma_notify_task' => <<<'SQL'
CREATE TABLE ma_notify_task (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `notify_no` varbinary(64) NOT NULL COMMENT '通知任务号，N加14位上海创建时间加5位随机数，共20位，全库唯一',
  `event_type` varchar(32) COLLATE utf8mb4_bin NOT NULL COMMENT '通知业务事件类型，当前PAY_SUCCESS表示支付成功',
  `ref_no` varbinary(64) NOT NULL COMMENT '事件关联编号，PAY_SUCCESS时等于pay_no；与event_type共同唯一',
  `biz_no` varbinary(64) NOT NULL COMMENT '所属业务单号，关联ma_biz_order.biz_no',
  `pay_no` varbinary(64) NOT NULL COMMENT '所属支付单号，关联ma_pay_order.pay_no',
  `notify_url` varchar(2048) COLLATE utf8mb4_bin NOT NULL COMMENT '冻结的商户通知地址，后续配置修改不替换此目标',
  `notify_data` json NOT NULL COMMENT '冻结的通知参数和签名JSON，重试复用，不得公开泄露签名材料',
  `credential_version_id` bigint(20) unsigned NOT NULL COMMENT '原订单签名KEY版本ID，关联ma_payment_app_key.id',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '通知调度状态（NotifyConstant）：0待发送，1发送中，2本次成功，3停止自动重试，4暂不通知；历史成功见first_ack_at',
  `retry_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '累计已认领发送次数，含首次及人工补发，不仅是失败重试次数',
  `manual_attempt_no` int(10) unsigned DEFAULT NULL COMMENT '本次人工单次补发获准的尝试序号；NULL表示没有单次补发授权',
  `next_retry_at` datetime DEFAULT NULL COMMENT '下次允许发送时间，上海时区；NULL表示未安排',
  `last_notify_at` datetime DEFAULT NULL COMMENT '最近认领发送的时间，上海时区；NULL尚未发送，不代表商户已接收',
  `last_response` varchar(512) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '最近发送结果的诊断原因码，见PaymentResultCode；不是商户原始响应正文',
  `claim_token` varbinary(64) DEFAULT NULL COMMENT '当前发送认领随机令牌；NULL表示没有活动发送租约',
  `lease_until` datetime DEFAULT NULL COMMENT '发送租约截止时间，上海时区；NULL未认领，过期结果须重新核验',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  `first_ack_at` datetime DEFAULT NULL COMMENT '商户首次有效确认通知时间，上海时区；NULL尚未确认，后续补发失败不覆盖',
  `released_at` datetime DEFAULT NULL COMMENT '首次解除暂缓通知时间，上海时区；NULL未解除或未曾暂缓，自动期限起算依据之一',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notify_no` (`notify_no`),
  UNIQUE KEY `uk_notify_event_ref` (`event_type`,`ref_no`),
  UNIQUE KEY `uk_notify_biz_event` (`biz_no`,`event_type`),
  KEY `idx_notify_created` (`created_at`,`id`),
  KEY `idx_notify_due` (`status`,`next_retry_at`),
  KEY `idx_notify_lease` (`status`,`lease_until`),
  KEY `idx_notify_pay` (`pay_no`),
  KEY `idx_notify_credential` (`credential_version_id`),
  CONSTRAINT `fk_notify_biz` FOREIGN KEY (`biz_no`) REFERENCES `ma_biz_order` (`biz_no`),
  CONSTRAINT `fk_notify_credential` FOREIGN KEY (`credential_version_id`) REFERENCES `ma_payment_app_key` (`id`),
  CONSTRAINT `fk_notify_pay` FOREIGN KEY (`pay_no`) REFERENCES `ma_pay_order` (`pay_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='支付成功的商户通知责任，冻结原签名和目标；调度状态与历史有效应答分开'
SQL,

    'ma_receipt_flow' => <<<'SQL'
CREATE TABLE ma_receipt_flow (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `receipt_scope_key` varbinary(64) NOT NULL COMMENT '收款匹配范围键，关联ma_receipt_scope.receipt_scope_key',
  `plugin_code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '识别到账事实的插件编码',
  `event_key` varbinary(128) NOT NULL COMMENT '插件来源事件标识，与范围及插件共同防重复消费',
  `payload_digest` char(64) COLLATE utf8mb4_bin NOT NULL COMMENT '归一化收款事实的SHA-256摘要，用于同事件一致性核对',
  `platform_trade_no` varbinary(128) DEFAULT NULL COMMENT '插件提供的交易编号，可为监听合成编号；NULL表示无编号，同插件内防重复消费',
  `amount` int(10) unsigned NOT NULL COMMENT '本条核实到账金额，单位分，不是业务订单原金额',
  `remark` varchar(256) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '付款备注；来源未提供时为NULL',
  `paid_at` datetime DEFAULT NULL COMMENT '来源提供并用于匹配的付款时间，上海时区；无来源时间时可为NULL',
  `received_at` datetime NOT NULL COMMENT '系统接收该到账事实的时间，上海时区，区别于实际付款时间',
  `reason_code` varchar(64) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '到账确认或依据补充的诊断原因码；NULL无附加原因',
  `pay_no` varbinary(64) NOT NULL COMMENT '最终匹配支付单号，关联ma_pay_order.pay_no，唯一且不得换绑',
  `evidence_json` json DEFAULT NULL COMMENT '经标准化和字段筛选的到账依据JSON；NULL无附加依据，不保留完整平台报文',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flow_pay` (`pay_no`),
  UNIQUE KEY `uk_flow_event` (`plugin_code`,`event_key`),
  UNIQUE KEY `uk_flow_plugin_trade` (`plugin_code`,`platform_trade_no`),
  KEY `idx_receipt_received` (`received_at`,`id`),
  KEY `idx_receipt_platform_trade` (`receipt_scope_key`,`platform_trade_no`),
  KEY `idx_receipt_paid` (`paid_at`,`pay_no`),
  CONSTRAINT `fk_flow_pay` FOREIGN KEY (`pay_no`) REFERENCES `ma_pay_order` (`pay_no`),
  CONSTRAINT `fk_flow_scope` FOREIGN KEY (`receipt_scope_key`) REFERENCES `ma_receipt_scope` (`receipt_scope_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='已最终匹配订单的到账事实，一条流水仅绑定一笔支付单，未匹配流水不入库'
SQL,

    'ma_refund_order' => <<<'SQL'
CREATE TABLE ma_refund_order (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `refund_no` varchar(20) COLLATE utf8mb4_bin NOT NULL COMMENT '内部退款单号，R加14位上海创建时间加5位随机数，共20位',
  `pay_no` varbinary(64) NOT NULL COMMENT '原支付单号，关联ma_pay_order.pay_no',
  `request_key` varbinary(73) NOT NULL COMMENT '退款幂等键：default为原单默认请求，merchant:加商户退款号为指定请求',
  `refund_amount` bigint(20) unsigned NOT NULL COMMENT '申请退款金额，单位分；处理中及未知结果继续占用可退额度',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '退款状态（RefundConstant）：0处理中，1成功，2未执行或拒绝，3结果待核实',
  `channel_refund_no` varbinary(128) DEFAULT NULL COMMENT '插件返回的平台退款编号；未提供时为NULL',
  `result_code` varchar(64) COLLATE utf8mb4_bin NOT NULL DEFAULT 'refund_processing' COMMENT '退款诊断原因码，见PaymentResultCode的refund系列，不替代退款状态',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refund_no` (`refund_no`),
  UNIQUE KEY `uk_refund_request` (`pay_no`,`request_key`),
  CONSTRAINT `fk_refund_pay` FOREIGN KEY (`pay_no`) REFERENCES `ma_pay_order` (`pay_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='原支付单的退款请求与幂等结果，未知或处理中不自动重发'
SQL,

    'ma_notify_attempt' => <<<'SQL'
CREATE TABLE ma_notify_attempt (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键，供系统内部关联',
  `notify_no` varbinary(64) NOT NULL COMMENT '所属通知任务号，关联ma_notify_task.notify_no',
  `attempt_no` int(10) unsigned NOT NULL COMMENT '任务内发送尝试序号，从1递增，包含人工补发',
  `claim_token` varbinary(64) NOT NULL COMMENT '本次发送认领令牌，防止旧工作者覆盖新尝试',
  `started_at` datetime NOT NULL COMMENT '认领并开始本次发送的时间，上海时区',
  `finished_at` datetime DEFAULT NULL COMMENT '本次尝试结果落库时间，上海时区；NULL尚未完成记录',
  `http_status` smallint(5) unsigned DEFAULT NULL COMMENT '商户HTTP响应状态码；未取得HTTP响应时为NULL',
  `duration_ms` int(10) unsigned DEFAULT NULL COMMENT '本次HTTP发送耗时，毫秒；无记录时为NULL',
  `result_code` varchar(64) COLLATE utf8mb4_bin NOT NULL COMMENT '本次通知结果码，见PaymentResultCode，如acknowledged、invalid_ack、timeout',
  `response_body` text COLLATE utf8mb4_bin COMMENT '商户响应的受限UTF-8片段，最多4096字节；NULL表示无可记录正文',
  `response_truncated` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '响应记录是否截断：0否，1是；不能据截断片段还原完整报文',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录创建时间，上海时区',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录最后更新时间，上海时区',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notify_attempt` (`notify_no`,`attempt_no`),
  CONSTRAINT `fk_attempt_notify` FOREIGN KEY (`notify_no`) REFERENCES `ma_notify_task` (`notify_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC COMMENT='每次商户通知发送的执行记录及受限响应片段'
SQL,

];
