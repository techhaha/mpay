<?php

declare(strict_types=1);

namespace app\controller;

use think\facade\Db;
use think\Request;
use think\facade\View;

class InstallController
{
    public function index()
    {
        // 检查是否已经安装过
        if ($this->checkLock()) {
            return redirect('User/login');
        };
        return View::fetch();
    }

    public function install(Request $request)
    {
        // 检查是否已经安装过
        if ($this->checkLock()) {
            return json(backMsg(1, '已经安装'));
        };
        // 检查环境
        $envCheck = $this->checkEnvironment();
        if ($envCheck !== true) {
            return json(backMsg(1, $envCheck));
        };
        // 获取表单提交的数据库配置信息
        $dbConfig = $request->post();
        // 保存数据库配置信息到配置文件
        if ($this->saveDbConfig($dbConfig) === false) {
            return json(backMsg(1, '配置保存失败'));
        } else {
            return json(backMsg(0, '配置保存成功'));
        };
    }
    // 初始化数据库
    public function init(Request $request)
    {
        // 检查是否已经安装过
        if ($this->checkLock()) {
            return backMsg(1, '已经安装');
        };
        // 获取表单提交的数据库配置信息
        $dbConfig = $request->post();

        // 连接数据库并建表
        $is_succ_tb = $this->createTables();

        // 初始化数据记录
        $is_succ_data = $this->initData($dbConfig);

        // 安装检测
        if (!$is_succ_tb) {
            return json(backMsg(1, '数据表创建失败'));
        }
        if (!$is_succ_data) {
            return json(backMsg(1, '数据初始化失败'));
        }
        // 安装成功，写入安装锁文件
        $this->setLock();
        return json(backMsg(0, '安装成功'));
    }
    private function checkEnvironment()
    {
        // 检查PHP版本
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            return 'PHP版本必须大于等于8.0';
        }
        // 检查文件上传写入权限
        if (!is_writable(sys_get_temp_dir())) {
            return '文件上传目录没有写入权限';
        }
        // 检查Fileinfo扩展是否安装
        if (!extension_loaded('fileinfo')) {
            return 'Fileinfo扩展未安装';
        }
        return true;
    }
    private function saveDbConfig($dbConfig)
    {
        $envPath = app()->getRootPath() . '.env';
        $envContent = <<<EOT
APP_DEBUG = false

DB_TYPE = mysql
DB_HOST = {$dbConfig['host']}
DB_NAME = {$dbConfig['name']}
DB_USER = {$dbConfig['user']}
DB_PASS = {$dbConfig['pass']}
DB_PORT = {$dbConfig['port']}
DB_CHARSET = {$dbConfig['charset']}
DB_PREFIX = mpay_

DEFAULT_LANG = zh-cn
EOT;
        return file_put_contents($envPath, $envContent);
    }

    private function createTables()
    {
        // 连接数据库
        $db = Db::connect();
        if ($db === false) {
            return false;
        }
        // 创建order表的 SQL 语句
        $sql = "CREATE TABLE `mpay_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '商户ID',
  `order_id` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '订单号',
  `type` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '支付类型',
  `out_trade_no` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '商户订单号',
  `notify_url` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '异步通知地址',
  `return_url` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '跳转通知地址',
  `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '商品名称',
  `really_price` float NOT NULL DEFAULT '0' COMMENT '实际支付金额',
  `money` float NOT NULL DEFAULT '0' COMMENT '订单价格',
  `clientip` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '用户IP地址',
  `device` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '设备类型',
  `param` varchar(720) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '扩展参数',
  `state` tinyint(4) NOT NULL DEFAULT '0' COMMENT '订单状态',
  `patt` tinyint(4) NOT NULL DEFAULT '0' COMMENT '开启回调监听',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '订单创建时间',
  `close_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '订单关闭时间',
  `pay_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '支付时间',
  `platform_order` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '收款平台订单号',
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '收款账号ID',
  `cid` int(11) NOT NULL DEFAULT '0' COMMENT '收款码ID',
  `delete_time` timestamp NULL DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=MyISAM DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;";

        // 执行 SQL 语句创建表
        $db->execute("DROP TABLE IF EXISTS `mpay_order`;");
        $db->execute($sql);

        // 创建pay_account表的 SQL 语句
        $sql = "CREATE TABLE `mpay_pay_account` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '收款平台ID',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `platform` varchar(255) NOT NULL DEFAULT '' COMMENT '收款平台',
  `account` varchar(255) NOT NULL DEFAULT '' COMMENT '账号',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '密码',
  `state` tinyint(4) NOT NULL DEFAULT '1' COMMENT '启用',
  `pattern` tinyint(4) NOT NULL DEFAULT '1' COMMENT '账号监听模式',
  `params` text NOT NULL DEFAULT '' COMMENT '自定义查询',
  `delete_time` timestamp NULL DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;";

        // 执行 SQL 语句创建表
        $db->execute("DROP TABLE IF EXISTS `mpay_pay_account`;");
        $db->execute($sql);

        // 创建pay_channel表的 SQL 语句
        $sql = "CREATE TABLE `mpay_pay_channel` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '渠道ID',
  `account_id` int(11) NOT NULL DEFAULT '0' COMMENT '收款平台ID',
  `channel` varchar(255) NOT NULL DEFAULT '' COMMENT '收款通道',
  `type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '保存类型',
  `qrcode` varchar(255) NOT NULL DEFAULT '' COMMENT '二维码',
  `last_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最近使用',
  `state` tinyint(4) NOT NULL DEFAULT '1' COMMENT '启用',
  `delete_time` timestamp NULL DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;";

        // 执行 SQL 语句创建表
        $db->execute("DROP TABLE IF EXISTS `mpay_pay_channel`;");
        $db->execute($sql);

        // 创建user表的 SQL 语句
        $sql = "CREATE TABLE `mpay_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '商户ID',
  `secret_key` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '商户秘钥',
  `nickname` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '用户昵称',
  `username` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '账号',
  `password` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '密码',
  `state` tinyint(4) NOT NULL DEFAULT '1' COMMENT '启用状态 0:禁用 1:启用',
  `role` tinyint(4) NOT NULL DEFAULT '0' COMMENT '用户角色 0:普通用户 1:管理员',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `delete_time` timestamp NULL DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;";

        // 执行 SQL 语句创建表
        $db->execute("DROP TABLE IF EXISTS `mpay_user`;");
        $db->execute($sql);
        return true;
    }

    private function initData($dbConfig)
    {
        // 连接数据库
        $db = Db::connect();
        $info = [
            'secret_key' => md5(1000 . time() . mt_rand()),
            'nickname' => $dbConfig['nickname'],
            'username' => $dbConfig['username'],
            'password' => password_hash($dbConfig['password'], PASSWORD_DEFAULT),
        ];
        // 初始化数据的 SQL 语句
        $sql = "INSERT INTO `mpay_user` (`id`, `pid`, `secret_key`, `nickname`, `username`, `password`, `state`, `role`) VALUES (1, 1000, :secret_key, :nickname, :username, :password, 1, 1);";

        // 执行 SQL 语句插入初始数据
        $is_succ = $db->execute($sql, $info);
        if (!$is_succ) {
            return false;
        }
        return true;
    }
    private function checkLock()
    {
        $path = runtime_path() . 'install.lock';
        return file_exists($path);
    }
    private function setLock()
    {
        $path = runtime_path() . 'install.lock';
        file_put_contents($path, time());
    }
}
