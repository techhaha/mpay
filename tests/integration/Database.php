<?php
declare(strict_types=1);

/** 此入口不加载业务数据库，凭证只能来自显式测试配置。 */
final class TestDatabase
{
    /** @var array<string,array{driver:string,version:string}> 只保留实际连接观察到的版本，不保存 DSN/用户名/配置路径。 */
    private static array $profiles = [];
    /** @var array<string,true> 只跟踪本进程创建且尚未确认删除的随机库，不扫描或清理其他进程数据库。 */
    private static array $remaining = [];
    private static int $createdCount = 0;

    /** @return list<array{driver:string,version:string}> 未连接数据库时为空，不能据此推断版本或连通性。 */
    public static function observedProfiles(): array { return array_values(self::$profiles); }

    /** @return array{owned_databases_created:int,owned_databases_remaining:int,owned_databases_removed:bool} */
    public static function cleanupSummary(): array
    {
        return ['owned_databases_created' => self::$createdCount, 'owned_databases_remaining' => count(self::$remaining),
            'owned_databases_removed' => self::$remaining === []];
    }
    /** @var list<string> 仅记录本对象实际创建成功的库名，cleanup 不按前缀批量扫描删除。 */
    private array $created = [];
    /** 建库后保留同一管理连接，清理不能因为达到连接上限而再申请连接失败。 */
    private ?PDO $ownerConnection = null;

    /**
     * @param array{host:string, port:int, user:string, password:string, database_prefix:string, allow_create_drop:bool} $config
     * @throws RuntimeException 专用账号、前缀或建删库开关不符合隔离条件。
     */
    public function __construct(public readonly array $config)
    {
        if (($config['database_prefix'] ?? '') !== 'mpay_v1_test_' || ($config['allow_create_drop'] ?? false) !== true
            || !str_starts_with($config['user'] ?? '', 'mpay_v1_test')) {
            throw new RuntimeException('测试数据库身份防护拒绝：需要专用用户、前缀和显式建删库授权');
        }
        if (!preg_match('/^[a-zA-Z0-9.:-]+$/D', $config['host'] ?? '') || !is_int($config['port'] ?? null)) {
            throw new RuntimeException('测试数据库连接配置无效');
        }
    }

    /** 只接受显式文件路径，缺失时立即拒绝；不回退到项目 .env 或默认数据库账号。 */
    public static function fromEnvironment(): self
    {
        $file = getenv('MPAY_TEST_CONFIG');
        if (!$file || !is_file($file)) {
            throw new RuntimeException('缺少 MPAY_TEST_CONFIG；不回退到业务 .env');
        }
        return new self(json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR));
    }

    /**
     * @param string|null $database 随机隔离库名；null 仅用于建删库的服务器连接。
     * @throws RuntimeException 库名不符合专用前缀和随机后缀格式。
     */
    public function connect(?string $database = null): PDO
    {
        if ($database !== null && !preg_match('/^mpay_v1_test_[a-f0-9]{16}$/D', $database)) {
            throw new RuntimeException('拒绝连接非本次隔离测试数据库');
        }
        $dsn = 'mysql:host=' . $this->config['host'] . ';port=' . $this->config['port'] . ';charset=utf8mb4';
        if ($database !== null) {
            $dsn .= ';dbname=' . $database;
        }
        $connection = new PDO($dsn, $this->config['user'], $this->config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+08:00',SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'",
        ]);
        $version = (string) $connection->getAttribute(PDO::ATTR_SERVER_VERSION);
        self::$profiles[$version] = ['driver' => 'mysql', 'version' => $version];
        return $connection;
    }

    /**
     * 新建独立空库并验证实际连接身份，不复用或清空任意已有数据库。
     *
     * @return array{0:string, 1:PDO} 新库名和其独立连接。
     */
    public function create(): array
    {
        $name = 'mpay_v1_test_' . bin2hex(random_bytes(8));
        $this->ownerConnection ??= $this->connect();
        $this->ownerConnection->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        $this->created[] = $name;
        self::$remaining[$name] = true;
        self::$createdCount++;
        $pdo = $this->connect($name);
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) {
            throw new RuntimeException('测试数据库实际身份不一致');
        }
        return [$name, $pdo];
    }

    /** 让真实容器、模型、事务和仓储使用同一隔离连接，不手工拼装业务依赖。 */
    public function bindApplication(think\App $app, string $database): PDO
    {
        if (!preg_match('/^mpay_v1_test_[a-f0-9]{16}$/D', $database)) {
            throw new RuntimeException('拒绝向应用装配非隔离数据库');
        }
        // 同一测试容器依次换随机库，先释放 ORM 的旧连接；不能把整批场景的连接累积到结束。
        $app->db->connect('mysql')->close();
        $config = $app->config->get('database');
        $config['connections']['mysql'] = array_replace($config['connections']['mysql'], [
            'hostname' => $this->config['host'], 'hostport' => $this->config['port'],
            'username' => $this->config['user'], 'password' => $this->config['password'], 'database' => $database,
        ]);
        $app->config->set($config, 'database');
        return $app->db->connect('mysql', true)->connect();
    }

    /** 在顶层 finally 中调用，只删除本对象成功创建的库；工作进程不承担父进程库的清理。 */
    public function cleanup(): void
    {
        while ($this->created !== []) {
            $name = $this->created[0];
            ($this->ownerConnection ??= $this->connect())->exec('DROP DATABASE ' . $name);
            // 逐项确认完成；中途失败保留尚未删除的精确列表，重试不会先碰已删除库。
            array_shift($this->created);
            unset(self::$remaining[$name]);
        }
        $this->ownerConnection = null;
    }
}
