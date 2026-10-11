<?php
declare(strict_types=1);

namespace app\service\system\install;

use app\service\payment\plugin\PaymentPluginFactoryService;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * 按版本顺序执行建表、受限追加/退役列、指定关联可空及注释更新，保存 pending/complete 并支持中断续跑。
 *
 * 数据库负责执行字段、索引和外键定义；本服务核对步骤摘要、表归属、基本表类型及本次注释结果。
 * 不比对完整元数据，不检测安装后人工修改的字段/索引，也不执行自动修表或回滚。
 */
final class MigrationService
{
    public const MINIMUM_MYSQL = '5.7.9';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** 原生 JSON/生成列使用 MySQL 5.7.9 及以上；MariaDB 不在当前支持范围。 */
    public static function assertVersion(string $version): void
    {
        if (stripos($version, 'mariadb') !== false || !preg_match('/^(\\d+\\.\\d+\\.\\d+)(?:[-+].*)?$/D', $version, $match)
            || version_compare($match[1], self::MINIMUM_MYSQL, '<')) {
            throw new RuntimeException('需要 MySQL ' . self::MINIMUM_MYSQL . ' 或更高版本');
        }
    }

    /**
     * 只读查看进度并让 MySQL 解析待建 SQL，不补写摘要/complete 或初始化字典。
     *
     * @return array{installed:bool,total_steps:int,completed_steps:int,pending_steps:int,versions:list<string>}
     * @throws RuntimeException 未知表、丢表、步骤被改动、进度不连续或已有迁移正在运行。
     */
    public function inspect(string $directory): array
    {
        $steps = $this->loadPlan($directory);
        return $this->locked(function () use ($steps): array {
            $state = $this->state($steps);
            $this->checkSyntax($steps, $state['tables']);
            $completed = count(array_filter($state['records'], static fn (array $record): bool => $record['state'] === 'complete'));
            return ['installed' => isset($state['tables']['_mpay_migration_step']), 'total_steps' => count($steps),
                'completed_steps' => $completed, 'pending_steps' => count($steps) - $completed,
                'versions' => array_values(array_unique(array_map(static fn (MigrationStep $step): string => $step->version, $steps)))];
        }, 0);
    }

    /**
     * DDL 不能整体回滚；每步先记录意图，成功后记录完成，中断时保留已经建好的表。
     *
     * @param string $directory 程序内固定的迁移目录，不接受 HTTP 或 CLI 自选路径。
     * @return list<string> 全部建表步骤完成的版本。
     * @throws RuntimeException 数据库归属或迁移记录不符，停止而不删除原数据。
     * @throws PDOException 建表或写进度失败，保留现场供同一版本继续。
     */
    public function migrate(string $directory): array
    {
        $steps = $this->loadPlan($directory);
        return $this->locked(function () use ($steps): array {
            $state = $this->state($steps);
            $this->checkSyntax($steps, $state['tables']);
            if (!isset($state['tables']['_mpay_migration_step'])) {
                // state 已确认整个库为空，不能在其他应用的数据库中补建迁移账本。
                $this->pdo->exec("CREATE TABLE _mpay_migration_step (
                    version VARCHAR(64) NOT NULL, step VARCHAR(64) NOT NULL,
                    checksum CHAR(64) NOT NULL, state VARCHAR(16) NOT NULL,
                    completed_at DATETIME NULL,
                    PRIMARY KEY (version,step)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
            }
            foreach ($steps as $step) {
                $record = $state['records'][$step->identity()] ?? null;
                // 已完成步骤只由 state 核对；后续明确退役的列不能被旧 ADD 步骤重新创建。
                if (($record['state'] ?? null) === 'complete') { continue; }
                if ($record === null) {
                    $this->pdo->prepare("INSERT INTO _mpay_migration_step (version,step,checksum,state) VALUES (?,?,?,'pending')")
                        ->execute([$step->version, $step->name, $step->checksum]);
                } elseif (!hash_equals($step->checksum, $record['checksum'])) {
                    // state 仅允许表尚未创建的 pending 步骤修正 SQL；真正执行前在迁移锁内更新原记录。
                    $update = $this->pdo->prepare("UPDATE _mpay_migration_step SET checksum=? WHERE version=? AND step=? AND state='pending' AND checksum=?");
                    $update->execute([$step->checksum, $step->version, $step->name, $record['checksum']]);
                    if ($update->rowCount() !== 1) { throw new RuntimeException('待建步骤记录已变化，请重新检查安装进度'); }
                }
                // 同摘要 pending 且表已存在，表示建表完成但确认中断；不重复 CREATE，也不清空该表。
                // 只有 state 已确认属于本次迁移的表可走此分支；同名未知表在执行前即被拒绝。
                if ($step->removedColumns !== null) {
                    $present = $this->removalColumnsPresent($step);
                    if ($present !== []) {
                        $wait = (int) $this->pdo->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
                        $this->pdo->exec('SET SESSION lock_wait_timeout=10');
                        try { $this->pdo->exec('ALTER TABLE `' . $step->table . '` DROP COLUMN `' . implode('`, DROP COLUMN `', $present) . '`'); }
                        finally { $this->pdo->exec('SET SESSION lock_wait_timeout=' . $wait); }
                    }
                } elseif ($step->nullableColumns !== null) {
                    if (($record['state'] ?? null) !== 'complete') { $this->applyNullable($step); }
                } elseif ($step->comments !== null) {
                    if (($record['state'] ?? null) !== 'complete') { $this->applyComments($step); }
                } elseif ($step->addedColumn !== null) {
                    // ADD COLUMN 不可回滚；pending 且目标列已存在时只核对该列并继续确认。
                    if (!$this->addedColumnExists($step)) { $this->pdo->exec($step->sql); }
                    if (!$this->addedColumnExists($step)) { throw new RuntimeException('列追加后未找到目标列'); }
                } elseif (!isset($state['tables'][$step->table])) {
                    $this->pdo->exec($step->sql);
                    $this->assertTable($step->table, $this->tables()[$step->table] ?? null);
                }
                // 初装字典先于该步 complete 写入；已安装后不重新添加管理员删除的支付方式。
                if ($step->comments === null && $step->addedColumn === null && $step->table === 'ma_payment_type' && ($record['state'] ?? null) !== 'complete') {
                    $this->pdo->exec("INSERT INTO ma_payment_type (code,name,enabled) VALUES ('alipay','支付宝',1),('wxpay','微信支付',1),('unionpay','云闪付',0) ON DUPLICATE KEY UPDATE name=IF(code='unionpay',VALUES(name),name)");
                }
                if ($step->comments === null && $step->table === 'ma_payment_plugin' && ($record['state'] ?? null) !== 'complete') {
                    // 仅初次安装初始化已交付插件；元信息读取不联网，写入仍严格使用本次迁移的 PDO。
                    $factory = app()->make(PaymentPluginFactoryService::class);
                    $insert = $this->pdo->prepare('INSERT INTO ma_payment_plugin (code,name,version,pay_types,class_name,config_schema,description,enabled) VALUES (?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE code=VALUES(code)');
                    foreach ($factory->codes() as $code) {
                        $definition = $factory->definition($code);
                        $insert->execute([$code, $definition->name, $definition->version, json_encode($definition->payTypes, JSON_THROW_ON_ERROR), $definition->className,
                            json_encode($definition->configSchema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $definition->description]);
                    }
                }
                if (($record['state'] ?? null) === 'complete') { continue; }
                $this->pdo->prepare("UPDATE _mpay_migration_step SET state='complete',completed_at=COALESCE(completed_at,NOW()) WHERE version=? AND step=?")
                    ->execute([$step->version, $step->name]);
            }
            return array_values(array_unique(array_map(static fn (MigrationStep $step): string => $step->version, $steps)));
        }, 10);
    }

    /** @return list<string> 固定建表集合，供安装服务检查已有业务数据，不连接数据库。 */
    public function managedTables(string $directory): array
    {
        // 迁移账本的注释也有版本记录，但账本本身不是初始化要检查的业务表。
        return array_values(array_unique(array_map(static fn (MigrationStep $step): string => $step->table,
            array_filter($this->loadPlan($directory), static fn (MigrationStep $step): bool => $step->comments === null))));
    }

    /** 初始化和迁移共用连接级命名锁；重入时每次取得都对应一次释放。 */
    public static function databaseLockName(string $database): string
    {
        if ($database === '') { throw new InvalidArgumentException('数据库锁缺少目标库'); }
        return 'mpay:migrate:' . substr(hash('sha256', $database), 0, 40);
    }

    /**
     * 文件顺序、文件内声明顺序决定建表次序；重复表名在写入数据库前拒绝。
     * 列追加必须跟在所属表的建表定义之后；只处理声明的追加列，不维护整表结构比较。
     *
     * @return list<MigrationStep>
     */
    private function loadPlan(string $directory): array
    {
        $files = glob(rtrim($directory, '/\\\\') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $steps = []; $tables = [];
        foreach ($files as $file) {
            $definitions = (static fn (string $path): mixed => require $path)($file);
            if (!is_array($definitions) || $definitions === []) { throw new RuntimeException('迁移文件未返回建表定义'); }
            foreach ($definitions as $name => $definition) {
                if (!is_string($name)) { throw new RuntimeException('迁移步骤缺少表名'); }
                $step = MigrationStep::fromDefinition(pathinfo($file, PATHINFO_FILENAME), $name, $definition);
                if ($step->nullableColumns !== null || $step->removedColumns !== null) {
                    if (!isset($tables[$step->table])) { throw new RuntimeException('可空迁移缺少前置建表定义'); }
                    $steps[] = $step;
                    continue;
                }
                if ($step->comments !== null) {
                    if ($step->table !== '_mpay_migration_step' && !isset($tables[$step->table])) {
                        throw new RuntimeException('注释迁移缺少前置建表定义');
                    }
                    $steps[] = $step;
                    continue;
                }
                if ($step->addedColumn === null && isset($tables[$step->table])) { throw new RuntimeException('迁移存在重复建表定义：' . $step->table); }
                if ($step->addedColumn !== null && !isset($tables[$step->table])) { throw new RuntimeException('列追加缺少前置建表定义'); }
                $steps[] = $step; $tables[$step->table] = true;
            }
        }
        if ($steps === []) { throw new RuntimeException('没有可执行的迁移文件'); }
        return $steps;
    }

    /**
     * 以账本证明建表归属：无记录的非空库、陌生表和已完成但丢失的表均拒绝续跑。
     * 已建表的 SQL 摘要不可变；仅 pending 且表未创建时允许修正失败的 SQL，读取时不更新账本。
     * pending 可对应尚未建表或已建普通 InnoDB 表；不能用这里的通过结果宣称字段与索引从未被人工修改。
     *
     * @param list<MigrationStep> $steps
     * @return array{tables:array<string,array>,records:array<string,array>}
     */
    private function state(array $steps): array
    {
        $tables = $this->tables();
        if (!isset($tables['_mpay_migration_step'])) {
            if ($tables !== []) { throw new RuntimeException('拒绝接管无迁移记录的非空数据库'); }
            return ['tables' => [], 'records' => []];
        }
        $this->assertTable('_mpay_migration_step', $tables['_mpay_migration_step']);
        $expected = []; $byTable = [];
        $removals = [];
        foreach ($steps as $step) {
            $expected[$step->identity()] = $step;
            if ($step->comments === null && $step->addedColumn === null && $step->nullableColumns === null && $step->removedColumns === null) { $byTable[$step->table] = $step->identity(); }
            foreach ($step->removedColumns ?? [] as $column) { $removals[$step->table . '.' . $column] = $step->identity(); }
        }
        if (array_diff(array_keys($tables), [...array_keys($byTable), '_mpay_migration_step']) !== []) {
            throw new RuntimeException('数据库含未声明的表，拒绝接管');
        }
        $records = [];
        foreach ($this->pdo->query('SELECT version,step,checksum,state FROM _mpay_migration_step')->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $identity = $record['version'] . ':' . $record['step'];
            if (!isset($expected[$identity]) || !preg_match('/^[a-f0-9]{64}$/D', $record['checksum'])
                || !in_array($record['state'], ['pending', 'complete'], true)) {
                throw new RuntimeException('迁移版本、SQL 摘要或进度状态不符');
            }
            $step = $expected[$identity];
            if (!hash_equals($step->checksum, $record['checksum'])
                && ($step->comments !== null || $step->nullableColumns !== null || $step->addedColumn !== null || $record['state'] !== 'pending' || isset($tables[$step->table]))) {
                throw new RuntimeException('已创建表的迁移 SQL 摘要已改变，拒绝覆盖');
            }
            $records[$identity] = $record;
        }
        $unfinished = false;
        foreach ($steps as $step) {
            $record = $records[$step->identity()] ?? null;
            if ($record !== null && $unfinished) { throw new RuntimeException('迁移进度不连续，需先核对中断步骤'); }
            if ($step->removedColumns !== null) {
                if (!isset($tables[$step->table])) {
                    if ($record !== null) { throw new RuntimeException('列退役迁移缺少所属表'); }
                } else {
                    $present = $this->removalColumnsPresent($step);
                    if ($record === null) {
                        foreach (array_diff($step->removedColumns, $present) as $column) {
                            foreach ($steps as $prior) {
                                if ($prior->table === $step->table && $prior->addedColumn === $column
                                    && ($records[$prior->identity()]['state'] ?? null) === 'complete') {
                                    throw new RuntimeException('列已缺失但没有退役迁移记录');
                                }
                            }
                        }
                    }
                    if (($record['state'] ?? null) === 'complete' && $present !== []) { throw new RuntimeException('已退役的列重新出现'); }
                }
            } elseif ($step->nullableColumns !== null) {
                if (isset($tables[$step->table])) {
                    $states = $this->nullableStates($step);
                    if ($record === null && in_array('YES', $states, true)) { throw new RuntimeException('目标列已可空但没有迁移记录'); }
                    if (($record['state'] ?? null) === 'complete' && in_array('NO', $states, true)) { throw new RuntimeException('已完成的可空迁移定义不符'); }
                } elseif ($record !== null) { throw new RuntimeException('可空迁移缺少所属表'); }
            } elseif ($step->comments !== null) {
                if ($record !== null && !isset($tables[$step->table])) { throw new RuntimeException('注释迁移缺少所属表'); }
            } elseif ($step->addedColumn !== null) {
                if (isset($tables[$step->table])) {
                    $exists = $this->addedColumnExists($step);
                    if ($exists && $record === null) { throw new RuntimeException('目标列已存在但没有对应迁移记录'); }
                    $removal = $removals[$step->table . '.' . $step->addedColumn] ?? null;
                    if (!$exists && ($record['state'] ?? null) === 'complete' && ($removal === null || !isset($records[$removal]))) {
                        throw new RuntimeException('已完成迁移的列缺失');
                    }
                } elseif ($record !== null) { throw new RuntimeException('列追加迁移缺少所属表'); }
            } elseif (isset($tables[$step->table])) {
                if ($record === null) { throw new RuntimeException('存在未登记的同名表：' . $step->table); }
                $this->assertTable($step->table, $tables[$step->table]);
            } elseif (($record['state'] ?? null) === 'complete') {
                throw new RuntimeException('已完成迁移的表缺失，拒绝自动重建：' . $step->table);
            }
            $unfinished = $unfinished || ($record['state'] ?? null) !== 'complete';
        }
        return ['tables' => $tables, 'records' => $records];
    }

    /** @return array<string,array{TABLE_TYPE:string,ENGINE:?string}> 只读取表类型和引擎，不读取字段、索引或业务数据。 */
    private function tables(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['TABLE_NAME']] = ['TABLE_TYPE' => $row['TABLE_TYPE'], 'ENGINE' => $row['ENGINE']];
        }
        return $result;
    }

    /** 视图和非事务引擎不能作为业务表；完整字段和约束由受控建表 SQL 交给 MySQL 执行。 */
    private function assertTable(string $name, ?array $table): void
    {
        if (($table['TABLE_TYPE'] ?? null) !== 'BASE TABLE' || strcasecmp($table['ENGINE'] ?? '', 'InnoDB') !== 0) {
            throw new RuntimeException('迁移需要普通 InnoDB 表：' . $name);
        }
    }

    /**
     * 只修改表/列 COMMENT，不从注释文件接收类型或默认值。
     * MySQL MODIFY 必须重述完整列声明，因此直接保留 SHOW CREATE 返回的原列定义，
     * 仅替换末尾 COMMENT；生成列、字符集、默认值及 ON UPDATE 均保留。
     * 每表一条 DDL，由 MySQL 选择执行算法；5.7 的存储生成列可能需要内部重建。
     * 中断后按实际注释补齐，已一致时不再执行 ALTER。
     *
     * @throws RuntimeException 表/列缺失或服务器声明无法安全识别；不得猜测字段类型后继续。
     * @throws PDOException 数据库拒绝 DDL，保留 pending 供修复后继续。
     */
    private function applyComments(MigrationStep $step): void
    {
        $comments = $step->comments ?? throw new LogicException('缺少注释声明');
        $table = '`' . $step->table . '`';
        $tableQuery = $this->pdo->prepare('SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $columnsQuery = $this->pdo->prepare('SELECT COLUMN_NAME,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $tableQuery->execute([$step->table]);
        $tableComment = $tableQuery->fetchColumn();
        if ($tableComment === false) { throw new RuntimeException('注释目标表不存在'); }
        $columnsQuery->execute([$step->table]);
        $columns = $columnsQuery->fetchAll(PDO::FETCH_KEY_PAIR);
        if (array_diff(array_keys($comments['columns']), array_keys($columns)) !== []) {
            throw new RuntimeException('注释声明引用了不存在的字段');
        }
        $changes = [];
        if ($tableComment !== $comments['comment']) { $changes[] = 'COMMENT=' . $this->pdo->quote($comments['comment']); }
        $changedColumns = array_filter($comments['columns'], static fn (string $comment, string $column): bool => $columns[$column] !== $comment, ARRAY_FILTER_USE_BOTH);
        if ($changedColumns !== []) {
            // SHOW CREATE 的带引号标识符是一行一个字段；不解析请求 SQL 或重新拼装完整类型元数据。
            $quoted = (int) $this->pdo->query('SELECT @@SESSION.sql_quote_show_create')->fetchColumn();
            if ($quoted !== 1) { $this->pdo->exec('SET SESSION sql_quote_show_create=1'); }
            try { $create = (string) $this->pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1]; }
            finally { if ($quoted !== 1) { $this->pdo->exec('SET SESSION sql_quote_show_create=' . $quoted); } }
            foreach ($changedColumns as $column => $comment) {
                if (!preg_match('/^  `' . preg_quote($column, '/') . '` (.+)$/m', $create, $match)) {
                    throw new RuntimeException('无法保留注释目标字段的原始声明');
                }
                $declaration = rtrim($match[1], ",\r");
                // 仅识别服务器输出末尾的 SQL 字符串；支持引号转义及已有注释中的反斜杠。
                $declaration = preg_replace("/ COMMENT '(?:[^'\\\\]|\\\\.|'')*'$/D", '', $declaration);
                if ($declaration === null || $declaration === '') { throw new RuntimeException('字段声明识别失败'); }
                $changes[] = 'MODIFY COLUMN `' . $column . '` ' . $declaration . ' COMMENT ' . $this->pdo->quote($comment);
            }
        }
        if ($changes === []) { return; }
        $wait = (int) $this->pdo->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
        $this->pdo->exec('SET SESSION lock_wait_timeout=10');
        try {
            // 不强制 INPLACE：实测 MySQL 5.7 会拒绝存储生成列的该算法；完整原声明保留其表达式和索引语义。
            $this->pdo->exec('ALTER TABLE ' . $table . ' ' . implode(', ', $changes));
        } finally { $this->pdo->exec('SET SESSION lock_wait_timeout=' . $wait); }
        $tableQuery->execute([$step->table]);
        $columnsQuery->execute([$step->table]);
        $actual = $columnsQuery->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($tableQuery->fetchColumn() !== $comments['comment']
            || array_diff_assoc($comments['columns'], $actual) !== []) {
            throw new RuntimeException('数据库注释写入后核对不一致');
        }
    }

    /** @return list<string> 只检查明示退役列；DDL 完成、账本 pending 时返回空集合以安全续跑。 */
    private function removalColumnsPresent(MigrationStep $step): array
    {
        $query = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$step->table]);
        return array_values(array_intersect($step->removedColumns ?? [], $query->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return array<string,string> 仅读取本次允许放宽的关联列，拒绝缺失列及非预期类型。 */
    private function nullableStates(MigrationStep $step): array
    {
        $expected = ['app_id' => 'bigint', 'pid' => 'varbinary', 'credential_version_id' => 'bigint',
            'notify_url' => 'varchar', 'poll_group_id' => 'bigint'];
        $query = $this->pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$step->table]); $states = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (!in_array($column['COLUMN_NAME'], $step->nullableColumns ?? [], true)) { continue; }
            if ($column['DATA_TYPE'] !== $expected[$column['COLUMN_NAME']]) { throw new RuntimeException('可空迁移目标列类型不符'); }
            $states[$column['COLUMN_NAME']] = $column['IS_NULLABLE'];
        }
        if (count($states) !== count($step->nullableColumns ?? [])) { throw new RuntimeException('可空迁移缺少目标列'); }
        return $states;
    }

    /** 只把指定关联列改为可空；保留 SHOW CREATE 原类型、注释和外键，pending 可按实际结果续跑。 */
    private function applyNullable(MigrationStep $step): void
    {
        $states = $this->nullableStates($step); $changes = [];
        $quoted = (int) $this->pdo->query('SELECT @@SESSION.sql_quote_show_create')->fetchColumn();
        if ($quoted !== 1) { $this->pdo->exec('SET SESSION sql_quote_show_create=1'); }
        try { $create = (string) $this->pdo->query('SHOW CREATE TABLE `' . $step->table . '`')->fetch(PDO::FETCH_NUM)[1]; }
        finally { if ($quoted !== 1) { $this->pdo->exec('SET SESSION sql_quote_show_create=' . $quoted); } }
        foreach ($states as $column => $nullable) {
            if ($nullable === 'YES') { continue; }
            if (!preg_match('/^  `' . preg_quote($column, '/') . '` (.+)$/m', $create, $match)) { throw new RuntimeException('无法保留目标列声明'); }
            $declaration = rtrim($match[1], ",\r");
            $declaration = preg_replace('/^((?:bigint(?:\([0-9]+\))? unsigned|varbinary\(32\)|varchar\(2048\))(?: CHARACTER SET [a-z0-9_]+)?(?: COLLATE [a-z0-9_]+)?) NOT NULL\b/i', '$1 NULL', $declaration, 1, $count);
            if ($count !== 1) { throw new RuntimeException('可空迁移目标不是预期关联列：' . $column); }
            $changes[] = 'MODIFY COLUMN `' . $column . '` ' . $declaration;
        }
        if ($changes !== []) {
            $wait = (int) $this->pdo->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
            $this->pdo->exec('SET SESSION lock_wait_timeout=10');
            try { $this->pdo->exec('ALTER TABLE `' . $step->table . '` ' . implode(', ', $changes)); }
            finally { $this->pdo->exec('SET SESSION lock_wait_timeout=' . $wait); }
        }
        if (in_array('NO', $this->nullableStates($step), true)) { throw new RuntimeException('可空迁移未完成'); }
    }

    /** 只检查本次明确追加的列，以支持 DDL 已完成但进度未确认的续跑；不是整表元数据比对。 */
    private function addedColumnExists(MigrationStep $step): bool
    {
        $statement = $this->pdo->prepare('SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $statement->execute([$step->table, $step->addedColumn]);
        $column = $statement->fetch(PDO::FETCH_ASSOC);
        if ($column === false) { return false; }
        $expected = match ($step->columnDefinition) {
            'DATETIME NULL DEFAULT NULL' => ['datetime', 'YES', null, false],
            'BIGINT UNSIGNED NULL DEFAULT NULL' => ['bigint', 'YES', null, true],
            'TINYINT UNSIGNED NOT NULL DEFAULT 1' => ['tinyint', 'NO', '1', true],
            'TINYINT UNSIGNED NOT NULL DEFAULT 0' => ['tinyint', 'NO', '0', true],
            'CHAR(32) NULL DEFAULT NULL UNIQUE' => ['char', 'YES', null, false],
            default => throw new LogicException('未知追加列合同'),
        };
        if ($column['DATA_TYPE'] !== $expected[0] || $column['IS_NULLABLE'] !== $expected[1]
            || ($column['COLUMN_DEFAULT'] === null ? null : (string) $column['COLUMN_DEFAULT']) !== $expected[2]
            || str_contains(strtolower($column['COLUMN_TYPE']), 'unsigned') !== $expected[3]) {
            throw new RuntimeException('目标追加列已存在但定义不符');
        }
        if ($step->columnDefinition === 'CHAR(32) NULL DEFAULT NULL UNIQUE') {
            $index = $this->pdo->prepare('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? GROUP BY INDEX_NAME HAVING MAX(NON_UNIQUE)=0 AND COUNT(*)=1 AND MAX(COLUMN_NAME)=?');
            $index->execute([$step->table, $step->addedColumn]);
            if (strtolower($column['COLUMN_TYPE']) !== 'char(32)' || $index->fetchColumn() === false) {
                throw new RuntimeException('追加幂等键的长度或唯一约束缺失');
            }
        }
        return true;
    }

    /**
     * 建表前由 MySQL 原生预处理解析全部待建 SQL，不执行 DDL，也不自行解析 SQL。
     * 仅检查语法，权限和外键等执行条件仍以实际建表结果为准；恢复 PDO 设置供后续 ORM 使用。
     *
     * @param list<MigrationStep> $steps
     * @param array<string,array> $tables 已核对归属的现有表集合。
     */
    private function checkSyntax(array $steps, array $tables): void
    {
        $emulated = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        try {
            foreach ($steps as $step) {
                // 注释 SQL 依赖服务器当前列声明，执行该步时生成；声明校验已在 loadPlan 完成。
                if ($step->comments !== null || $step->nullableColumns !== null || $step->removedColumns !== null) { continue; }
                if ($step->addedColumn === null && isset($tables[$step->table])) { continue; }
                // 空库时 ALTER 依赖尚未执行的 CREATE；所属表建成后由 MySQL 执行该固定追加语句。
                if ($step->addedColumn !== null && (!isset($tables[$step->table]) || $this->addedColumnExists($step))) { continue; }
                $statement = $this->pdo->prepare($step->sql);
                $statement = null;
            }
        } finally { $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated); }
    }

    /**
     * @template T
     * @param callable():T $operation 不在数据库事务中执行 DDL；命名锁在异常时也释放。
     * @return T
     */
    private function locked(callable $operation, int $wait): mixed
    {
        if ($this->pdo->inTransaction()) { throw new LogicException('迁移不能置于业务事务中'); }
        self::assertVersion((string) $this->pdo->query('SELECT VERSION()')->fetchColumn());
        $lock = self::databaseLockName((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $claim = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $claim->execute([$lock, $wait]);
        if ((int) $claim->fetchColumn() !== 1) { throw new RuntimeException('迁移或初始化正在执行，请稍后重试'); }
        try { return $operation(); }
        finally { $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    }
}
