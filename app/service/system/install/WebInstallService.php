<?php
declare(strict_types=1);

namespace app\service\system\install;

use app\common\constant\ApiResponseConstant;
use app\common\util\SafeLogger;
use app\exception\BusinessException;
use app\service\system\install\InitializationService;
use app\service\system\install\MigrationService;
use think\App;

/** 网页安装用例：检查目标、复用迁移/初始化并保存私有配置；不另写建表 SQL 或创建远端数据库。 */
final class WebInstallService
{
    public function __construct(private readonly App $app, private readonly InstallationFiles $files, private readonly SafeLogger $logger) {}

    /** 首页只查本地状态；已有数据库配置时仍可显式访问 /install，数据库非空检查在提交时执行。 */
    public function needsSetup(): bool
    {
        return !$this->files->completed() && ($this->files->pending()
            || (string) $this->app->config->get('database.connections.mysql.database', '') === '');
    }

    /**
     * 页面只得到检查结果和非秘密默认项，不读取数据库或返回密码/配置文件全文。
     * @return array{ready:bool,checks:list<array{name:string,value:string,ok:bool}>,database:array,pending:bool}
     */
    public function environment(): array
    {
        $checks = [['name' => 'PHP 版本', 'value' => PHP_VERSION . '（需要 8.2 或以上兼容版本）', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')]];
        foreach (['pdo_mysql', 'curl', 'openssl', 'mbstring', 'dom', 'gd', 'fileinfo', 'bcmath', 'ctype', 'iconv'] as $extension) {
            $checks[] = ['name' => 'PHP ' . $extension, 'value' => extension_loaded($extension) ? '已加载' : '未加载', 'ok' => extension_loaded($extension)];
        }
        $curl = extension_loaded('curl') && version_compare(curl_version()['version'], '7.59.0', '>=');
        $checks[] = ['name' => 'cURL 传输', 'value' => '需要 libcurl 7.59.0 或以上', 'ok' => $curl];
        foreach (['项目配置目录' => $this->app->getRootPath(), '运行目录' => $this->app->getRuntimePath(),
            '收款图片目录' => $this->app->getRootPath() . 'public/storage'] as $name => $path) {
            $parent = $path;
            while (!is_dir($parent) && dirname(rtrim($parent, '/\\')) !== rtrim($parent, '/\\')) { $parent = dirname(rtrim($parent, '/\\')); }
            $ok = is_writable($parent) && !is_link(rtrim($path, '/\\'));
            $checks[] = ['name' => $name, 'value' => $ok ? '可写' : '请调整目录权限', 'ok' => $ok];
        }
        $db = $this->app->config->get('database.connections.mysql');
        return ['ready' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks,
            'database' => ['host' => (string) $db['hostname'], 'port' => (int) $db['hostport'], 'name' => (string) $db['database'], 'user' => (string) $db['username']],
            'pending' => $this->files->pending()];
    }

    /**
     * 检查连接及库内状态；不会创建数据库、执行迁移或保存提交的密码。
     * @param array{host:string,port:int,name:string,user:string,pass:string} $database
     * @return array{ready:bool,message:string} 已迁移但无业务数据的库也可以继续初始化。
     */
    public function check(array $database): array
    {
        return $this->files->exclusive(function () use ($database): array {
            $this->assertOpen();
            $this->assertExistingTarget($database);
            if ($this->files->pending()) { $this->files->begin($database); }
            $state = $this->connectAndInspect($database);
            if ($state['phase'] === 'initialized' && $this->files->pending()) {
                // 初始化已提交但浏览器/文件完成响应中断时，只补安装锁，不重设账号、密码或 KEY。
                $this->files->complete();
                return ['ready' => false, 'message' => '安装已完成，请进入管理后台登录'];
            }
            $this->assertEmpty($state);
            return ['ready' => true, 'message' => '数据库检查通过，请设置管理员账户'];
        });
    }

    /**
     * DDL 与管理员事务分开。阶段失败保留迁移账本和原密钥，重试核对原目标后继续。
     * @param array{database:array,administrator:array} $input 来自 InstallValidator 的完整输入。
     * @return array{login_url:string,message:string} 不返回数据库密码、管理员密码或应用 KEY。
     */
    public function install(array $input): array
    {
        return $this->files->exclusive(function () use ($input): array {
            $this->assertOpen();
            if (!$this->environment()['ready']) { throw new BusinessException('请先完成环境检查中的必需项目', ApiResponseConstant::CONFLICT); }
            $database = $input['database'];
            $this->assertExistingTarget($database);
            $pending = $this->files->pending();
            if ($pending) { $this->files->begin($database); }
            $state = $this->connectAndInspect($database);
            if ($pending && $state['phase'] === 'initialized') {
                $this->files->complete();
                return $this->result();
            }
            $this->assertEmpty($state);
            $this->files->begin($database);
            $stage = 'migration';
            try {
                $stage = 'migration';
                $pdo = $this->app->db->connect()->connect();
                (new MigrationService($pdo))->migrate($this->app->getRootPath() . 'database/migrations');
                $stage = 'configuration';
                // 先持久化连接与部署设置，再提交管理员；即使之后断开，也能用原配置恢复安装。
                $this->files->saveEnvironment([
                    'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_NAME' => $database['name'],
                    'DB_USER' => $database['user'], 'DB_PASS' => $database['pass'], 'APP_DEBUG' => 'false',
                    'MPAY_ENV' => (string) $this->app->config->get('mpay.environment', 'production'),
                ]);
                $stage = 'initialization';
                $admin = $input['administrator'];
                $this->app->make(InitializationService::class, [], true)->initialize($admin['username'], $admin['password'], $admin['app_name'], $admin['pid'] ?? null);
                $stage = 'lock';
                $this->files->complete();
                return $this->result();
            } catch (BusinessException $error) { throw $error; }
            catch (\Throwable $error) {
                $this->logger->exception($error);
                $message = match ($stage) {
                    'migration' => '数据表创建或检查未完成，请检查数据库权限和安装日志后继续',
                    'configuration' => '无法保存站点配置，请检查项目目录的写入权限',
                    'initialization' => '账户设置结果尚未确认，请点击“检查安装结果”核对',
                    default => '账户设置已保存，安装完成标记写入失败，请检查运行目录的写入权限',
                };
                throw new BusinessException($message, ApiResponseConstant::SERVICE_UNAVAILABLE, [], $error);
            }
        });
    }

    /** 沿用已有 .env 的数据库目标，避免网页安装将一个已配置站点指向另一库。 */
    private function assertExistingTarget(array $database): void
    {
        $configured = $this->app->config->get('database.connections.mysql');
        if ((string) $configured['database'] !== '' && [(string) $configured['hostname'], (int) $configured['hostport'], (string) $configured['database']]
            !== [$database['host'], $database['port'], $database['name']]) {
            throw new BusinessException('站点已有数据库配置，请使用预填的数据库地址、端口和名称继续安装', ApiResponseConstant::CONFLICT);
        }
    }

    /**
     * 只在安装请求里替换框架当前连接配置，复用同一 ORM/PDO；不会修改全局 PHP 或其他应用。
     * @return array<string,mixed> 初始化服务的只读状态，绝不把原异常正文公开。
     */
    private function connectAndInspect(array $database): array
    {
        try {
            $config = $this->app->config->get('database');
            $config['connections']['mysql'] = array_replace($config['connections']['mysql'], [
                'hostname' => $database['host'], 'hostport' => $database['port'], 'database' => $database['name'],
                'username' => $database['user'], 'password' => $database['pass'],
            ]);
            $config['connections']['mysql']['params'][\PDO::ATTR_TIMEOUT] = 5;
            $this->app->config->set($config, 'database');
            $pdo = $this->app->db->connect('mysql', true)->connect();
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            try { MigrationService::assertVersion($version); }
            catch (\RuntimeException) { throw new BusinessException('需要 MySQL ' . MigrationService::MINIMUM_MYSQL . ' 或更高版本', ApiResponseConstant::CONFLICT); }
            $state = $this->app->make(InitializationService::class, [], true)->status();
            if ($state['phase'] === 'schema_pending' && $state['schema']['installed']) {
                // 中断的 DDL 可以继续，但已有表中出现业务记录时不能据“迁移未完”绕过非空保护。
                $managed = (new MigrationService($pdo))->managedTables($this->app->getRootPath() . 'database/migrations');
                $present = array_values(array_intersect($managed, $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN)));
                if ($this->app->make(\app\repository\system\InitializationRepository::class)->occupiedTables($present) !== []) {
                    throw new BusinessException('数据库中已有业务数据，无法继续安装。请核对数据库信息', ApiResponseConstant::CONFLICT);
                }
            }
            return $state;
        } catch (BusinessException $error) { throw $error; }
        catch (\Throwable $error) {
            $this->logger->exception($error);
            throw new BusinessException('数据库检查失败，请核对连接信息、访问权限和数据库内容。详细原因请查看安装日志', ApiResponseConstant::CONFLICT, [], $error);
        }
    }

    private function assertOpen(): void
    {
        if ($this->files->completed()) { throw new BusinessException('系统已经安装，安装入口已关闭', ApiResponseConstant::CONFLICT); }
    }

    /** 只允许空库或可信迁移后的空业务库；重复安装不能删除管理员、应用、订单或旧密钥。 */
    private function assertEmpty(array $state): void
    {
        if (!in_array($state['phase'], ['empty', 'schema_pending'], true)) {
            throw new BusinessException('此数据库已包含系统数据，无法重复安装。已安装的站点请直接登录', ApiResponseConstant::CONFLICT);
        }
    }

    private function result(): array { return ['login_url' => '/admin/login', 'message' => '安装完成，请使用刚设置的管理员账户登录']; }
}
