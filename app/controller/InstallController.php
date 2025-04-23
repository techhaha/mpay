<?php

declare(strict_types=1);

namespace app\controller;

use think\facade\Db;
use think\Request;
use think\facade\View;
use think\facade\Log;
use think\exception\ValidateException;
use think\Validate;

class InstallController
{
    private const INSTALL_LOCK_FILE = 'install.lock';

    /**
     * 连接数据库
     * @return \think\db\Connection
     */
    private function connectDatabase()
    {
        return Db::connect();
    }

    /**
     * 首页，检查是否已安装，若已安装则跳转到登录页，否则显示安装页面
     * @return \think\response\Redirect|\think\response\View
     */
    public function index()
    {
        if ($this->checkLock()) {
            return redirect('User/login');
        }
        return View::fetch();
    }

    /**
     * 安装操作，检查环境、保存数据库配置信息
     * @param Request $request
     * @return \think\response\Json
     */
    public function install(Request $request)
    {
        if ($this->checkLock()) {
            return json(backMsg(1, '已经安装'));
        }

        $envCheck = $this->checkEnvironment();
        if ($envCheck !== true) {
            return json(backMsg(1, $envCheck));
        }

        $dbConfig = $request->post();
        try {
            $this->validateDbConfig($dbConfig);
            $this->saveDbConfig($dbConfig);
            return json(backMsg(0, '配置保存成功'));
        } catch (ValidateException $e) {
            return json(backMsg(1, $e->getMessage()));
        } catch (\Exception $e) {
            Log::error("保存数据库配置失败: " . $e->getMessage());
            return json(backMsg(1, '配置保存失败'));
        }
    }

    /**
     * 初始化数据库，创建表并初始化数据
     * @param Request $request
     * @return \think\response\Json
     */
    public function init(Request $request)
    {
        if ($this->checkLock()) {
            return json(backMsg(1, '已经安装'));
        }

        $dbConfig = $request->post();
        $startTime = microtime(true);

        try {
            $this->validateInitData($dbConfig);
            $this->connectDatabase()->transaction(function () use ($dbConfig) {
                $this->createTables();
                $this->initData($dbConfig);
            });
            $this->setLock();
            $endTime = microtime(true);
            Log::info("数据库初始化完成，耗时: " . ($endTime - $startTime) . " 秒");
            return json(backMsg(0, '安装成功'));
        } catch (ValidateException $e) {
            return json(backMsg(1, $e->getMessage()));
        } catch (\Exception $e) {
            Log::error("数据库初始化失败: " . $e->getMessage());
            return json(backMsg(1, '数据库初始化失败'));
        }
    }

    /**
     * 检查环境，包括 PHP 版本、文件上传写入权限、Fileinfo 扩展
     * @return bool|string
     */
    private function checkEnvironment()
    {
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            return 'PHP 版本必须大于等于 8.0';
        }

        if (!is_writable(sys_get_temp_dir())) {
            return '文件上传目录没有写入权限';
        }

        if (!extension_loaded('fileinfo')) {
            return 'Fileinfo 扩展未安装';
        }

        return true;
    }

    /**
     * 验证数据库配置信息
     * @param array $dbConfig
     * @throws ValidateException
     */
    private function validateDbConfig(array $dbConfig)
    {
        $validate = new Validate();
        $rule = [
            'host' => 'require',
            'name' => 'require',
            'user' => 'require',
            'pass' => 'require',
            'port' => 'require|integer',
        ];
        if (!$validate->rule($rule)->check($dbConfig)) {
            throw new ValidateException($validate->getError());
        }
    }

    /**
     * 验证初始化数据信息
     * @param array $dbConfig
     * @throws ValidateException
     */
    private function validateInitData(array $dbConfig)
    {
        $validate = new Validate();
        $rule = [
            'nickname' => 'require',
            'username' => 'require',
            'password' => 'require'
        ];
        if (!$validate->rule($rule)->check($dbConfig)) {
            throw new ValidateException($validate->getError());
        }
    }

    /**
     * 保存数据库配置信息到 .env 文件
     * @param array $dbConfig
     * @throws \Exception
     */
    private function saveDbConfig(array $dbConfig)
    {
        $envPath = app()->getRootPath() . '.env';
        $envContent = $this->generateEnvContent($dbConfig);
        if (file_put_contents($envPath, $envContent) === false) {
            throw new \Exception("无法写入 .env 文件");
        }
    }

    /**
     * 生成 .env 文件内容
     * @param array $dbConfig
     * @return string
     */
    private function generateEnvContent(array $dbConfig): string
    {
        return <<<EOT
APP_DEBUG = false

DB_TYPE = mysql
DB_HOST = {$dbConfig['host']}
DB_NAME = {$dbConfig['name']}
DB_USER = {$dbConfig['user']}
DB_PASS = {$dbConfig['pass']}
DB_PORT = {$dbConfig['port']}
DB_PREFIX = mpay_

DEFAULT_LANG = zh-cn
EOT;
    }

    /**
     * 创建数据库表
     * @throws \Exception
     */
    private function createTables()
    {
        $db = $this->connectDatabase();
        $tables = $this->getTableCreationSqls();

        foreach ($tables as $tableName => $sql) {
            try {
                $db->execute("DROP TABLE IF EXISTS `$tableName`;");
                $db->execute($sql);
                Log::info("$tableName 表创建成功");
            } catch (\Exception $e) {
                throw new \Exception("创建 $tableName 表失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 获取表创建的 SQL 语句
     * @return array
     */
    private function getTableCreationSqls(): array
    {
        return [
            'mpay_order' => "CREATE TABLE `mpay_order` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `pid` int(11) NOT NULL DEFAULT 0,
                `order_id` varchar(255) NOT NULL DEFAULT '',
                `type` varchar(255) NOT NULL DEFAULT '',
                `out_trade_no` varchar(255) NOT NULL DEFAULT '',
                `notify_url` varchar(512) NOT NULL DEFAULT '',
                `return_url` varchar(512) NOT NULL DEFAULT '',
                `name` varchar(255) NOT NULL DEFAULT '',
                `really_price` decimal(10, 2) NOT NULL DEFAULT 0.00,
                `money` decimal(10, 2) NOT NULL DEFAULT 0.00,
                `clientip` varchar(255) NOT NULL DEFAULT '',
                `device` varchar(255) NOT NULL DEFAULT '',
                `param` varchar(720) NOT NULL DEFAULT '',
                `state` tinyint(4) NOT NULL DEFAULT 0,
                `patt` tinyint(4) NOT NULL DEFAULT 0,
                `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
                `close_time` datetime DEFAULT NULL,
                `pay_time` datetime DEFAULT NULL,
                `platform` varchar(15) NOT NULL DEFAULT '',
                `platform_order` varchar(255) NOT NULL DEFAULT '',
                `aid` int(11) NOT NULL DEFAULT 0,
                `cid` int(11) NOT NULL DEFAULT 0,
                `delete_time` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_order_id` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;",
            'mpay_pay_account' => "CREATE TABLE `mpay_pay_account` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `pid` int(11) NOT NULL DEFAULT 0,
                `platform` varchar(255) NOT NULL DEFAULT '',
                `account` varchar(255) NOT NULL DEFAULT '',
                `password` varchar(255) NOT NULL DEFAULT '',
                `state` tinyint(4) NOT NULL DEFAULT 1,
                `pattern` tinyint(4) NOT NULL DEFAULT 1,
                `params` text NOT NULL,
                `delete_time` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;",
            'mpay_pay_channel' => "CREATE TABLE `mpay_pay_channel` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `account_id` int(11) NOT NULL DEFAULT 0,
                `channel` varchar(255) NOT NULL DEFAULT '',
                `type` tinyint(4) NOT NULL DEFAULT 0,
                `qrcode` varchar(512) NOT NULL DEFAULT '',
                `last_time` datetime DEFAULT CURRENT_TIMESTAMP,
                `state` tinyint(4) NOT NULL DEFAULT 1,
                `delete_time` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;",
            'mpay_user' => "CREATE TABLE `mpay_user` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `pid` int(11) NOT NULL DEFAULT 0,
                `secret_key` varchar(255) NOT NULL DEFAULT '',
                `nickname` varchar(255) NOT NULL DEFAULT '',
                `username` varchar(255) NOT NULL DEFAULT '',
                `password` varchar(255) NOT NULL DEFAULT '',
                `state` tinyint(4) NOT NULL DEFAULT 1,
                `role` tinyint(4) NOT NULL DEFAULT 0,
                `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
                `delete_time` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;",
        ];
    }

    /**
     * 初始化数据
     * @param array $dbConfig
     * @throws \Exception
     */
    private function initData(array $dbConfig)
    {
        $db = $this->connectDatabase();
        $info = [
            'secret_key' => md5(1000 . time() . mt_rand()),
            'nickname' => $dbConfig['nickname'],
            'username' => $dbConfig['username'],
            'password' => password_hash($dbConfig['password'], PASSWORD_DEFAULT),
            'create_time' => date('Y-m-d H:i:s'),
        ];

        $sql = "INSERT INTO `mpay_user` (`id`, `pid`, `secret_key`, `nickname`, `username`, `password`, `state`, `role`, `create_time`) VALUES (1, 1000, :secret_key, :nickname, :username, :password, 1, 1, :create_time);";

        try {
            $db->execute($sql, $info);
            Log::info("mpay_user 表数据初始化成功");
        } catch (\Exception $e) {
            throw new \Exception("初始化 mpay_user 表数据失败: " . $e->getMessage());
        }
    }

    /**
     * 检查是否已安装
     * @return bool
     */
    private function checkLock()
    {
        $path = runtime_path() . self::INSTALL_LOCK_FILE;
        return file_exists($path);
    }

    /**
     * 设置安装锁
     * @throws \Exception
     */
    private function setLock()
    {
        $path = runtime_path() . self::INSTALL_LOCK_FILE;
        if (file_put_contents($path, time()) === false) {
            throw new \Exception("无法写入安装锁文件");
        }
    }
}
