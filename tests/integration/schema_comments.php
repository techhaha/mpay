<?php
declare(strict_types=1);

use app\service\system\install\MigrationService;

require_once __DIR__ . '/Database.php';
$database = TestDatabase::fromEnvironment();
$directory = dirname(__DIR__, 2) . '/database/migrations';
$work = dirname(__DIR__, 2) . '/runtime/tests/schema-comments/' . bin2hex(random_bytes(8));
mkdir($work . '/legacy', 0770, true);
mkdir($work . '/changed', 0770, true);
foreach (glob($directory . '/*.php') as $file) {
    copy($file, $work . '/changed/' . basename($file));
    if (basename($file) !== '202610100200_schema_comments.php') { copy($file, $work . '/legacy/' . basename($file)); }
}

// Compare all structural attributes except comments; index statistics/cardinality are not structure.
$structure = static function (PDO $pdo): array {
    $columns = $pdo->query('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as &$row) { unset($row['COLUMN_COMMENT']); }
    unset($row);
    return [$columns,
        $pdo->query('SELECT TABLE_NAME,ENGINE,ROW_FORMAT,TABLE_COLLATION,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC),
    ];
};
$rows = static function (PDO $pdo, bool $ledger = false): array {
    $result = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!$ledger && $table === '_mpay_migration_step') { continue; }
        $result[$table] = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY ' . ($table === '_mpay_migration_step' ? 'version,step' : 'id'))->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
};
$coverage = static function (PDO $pdo) use ($suite): void {
    $suite->same(30, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn());
    $suite->same(341, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn());
    $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_COMMENT=''")->fetchColumn());
    $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_COMMENT=''")->fetchColumn());
};
try {
    $suite->test('注释迁移覆盖新装全部表字段且重跑不改记录', function () use ($suite, $database, $directory, $coverage, $rows): void {
        [, $pdo] = $database->create(); $migration = new MigrationService($pdo);
        $migration->migrate($directory); $coverage($pdo);
        $suite->same(64, $migration->inspect($directory)['completed_steps']);
        $suite->same(29, count($migration->managedTables($directory)));
        $before = $rows($pdo, true); $migration->migrate($directory);
        $suite->same($before, $rows($pdo, true));
    });

    $suite->test('注释升级保留原迁移摘要、数据及全部字段索引属性', function () use ($suite, $database, $directory, $work, $structure, $rows, $coverage): void {
        [, $pdo] = $database->create(); $migration = new MigrationService($pdo);
        $migration->migrate($work . '/legacy');
        $pdo->exec("INSERT INTO ma_admin_user(username,password_hash) VALUES ('comment_fixture','synthetic-hash-only')");
        $pdo->exec('ALTER TABLE ma_admin_user AUTO_INCREMENT=42');
        $pdo->prepare('INSERT INTO ma_system_config(config_key,value_json) VALUES (?,?)')->execute(['fixture', json_encode(['text' => "中文 ' \\ quote", 'empty' => null])]);
        $pdo->prepare('INSERT INTO ma_payment_app(pid,name,allowed_hosts) VALUES (?,?,?)')->execute(["binary\0pid", '注释数据保留', '[]']);
        $pdo->exec("UPDATE ma_payment_plugin SET updated_at='2020-01-01 00:00:00'");
        $beforeRows = $rows($pdo); $beforeStructure = $structure($pdo);
        $oldLedger = $pdo->query('SELECT * FROM _mpay_migration_step ORDER BY version,step')->fetchAll(PDO::FETCH_ASSOC);
        $migration->migrate($directory); $coverage($pdo);
        $suite->same($beforeRows, $rows($pdo));
        $suite->same($beforeStructure, $structure($pdo));
        $suite->same($oldLedger, $pdo->query("SELECT * FROM _mpay_migration_step WHERE version <> '202610100200_schema_comments' ORDER BY version,step")->fetchAll(PDO::FETCH_ASSOC));
        $suite->throws(fn () => $pdo->exec("INSERT INTO ma_admin_user(username,password_hash) VALUES ('another_fixture','synthetic-only')"), PDOException::class);
    });

    $suite->test('注释DDL后中断可续跑且篡改声明摘要被拒绝', function () use ($suite, $database, $directory, $work, $coverage): void {
        [, $pdo] = $database->create(); $migration = new MigrationService($pdo); $migration->migrate($directory);
        $pdo->exec("UPDATE _mpay_migration_step SET state='pending',completed_at=NULL WHERE version='202610100200_schema_comments' AND step='_mpay_migration_step_comments'");
        $suite->same(1, $migration->inspect($directory)['pending_steps']);
        $migration->migrate($directory); $suite->same(0, $migration->inspect($directory)['pending_steps']); $coverage($pdo);
        $file = $work . '/changed/202610100200_schema_comments.php';
        file_put_contents($file, str_replace('自增主键，供系统内部关联', '被改写的说明', file_get_contents($file)));
        $suite->throws(fn () => $migration->inspect($work . '/changed'), RuntimeException::class);
    });

    $suite->test('注释替换正确处理引号并拒绝不存在字段', function () use ($suite, $database, $directory, $work, $structure): void {
        [, $pdo] = $database->create(); $migration = new MigrationService($pdo); $migration->migrate($directory);
        $extra = $work . '/extra'; mkdir($extra);
        foreach (glob($directory . '/*.php') as $file) { copy($file, $extra . '/' . basename($file)); }
        $definition = ['ma_system_config_comments' => ['table' => 'ma_system_config', 'comments' => [
            'comment' => "中文 ' quote \\ table", 'columns' => ['config_key' => "中文 ' quote \\ column"],
        ]]];
        file_put_contents($extra . '/202610100300_fixture.php', '<?php return ' . var_export($definition, true) . ';');
        $before = $structure($pdo); $migration->migrate($extra); $suite->same($before, $structure($pdo));
        $suite->same($definition['ma_system_config_comments']['comments']['columns']['config_key'], $pdo->query("SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ma_system_config' AND COLUMN_NAME='config_key'")->fetchColumn());
        // Replacing an already quoted comment must strip only COMMENT, not a default or other attribute.
        $definition['ma_system_config_comments']['comments']['columns']['config_key'] = '再次修改说明';
        file_put_contents($extra . '/202610100400_fixture.php', '<?php return ' . var_export($definition, true) . ';');
        $migration->migrate($extra); $suite->same($before, $structure($pdo));
        $definition['ma_system_config_comments']['comments']['columns'] = ['absent_field' => '不可新增字段'];
        file_put_contents($extra . '/202610100500_fixture.php', '<?php return ' . var_export($definition, true) . ';');
        $suite->throws(fn () => $migration->migrate($extra), RuntimeException::class);
        $suite->same($before, $structure($pdo));
    });
} finally { $database->cleanup(); }
