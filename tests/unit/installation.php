<?php
declare(strict_types=1);

use app\exception\BusinessException;
use app\http\install\validation\InstallValidator;
use app\service\system\install\MigrationService;
use app\service\system\install\MigrationStep;

// 只登记待用户执行的安装合同；不在开发加载检查中运行这些断言。
$suite->test('网页安装拒绝 DSN 注入和宽松端口类型', function () use ($suite): void {
    $validator = new InstallValidator();
    $database = ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'mpay_v1_fixture', 'user' => 'fixture_user', 'pass' => 'synthetic-only'];
    foreach ([['host' => '127.0.0.1;dbname=another'], ['name' => 'mpay;port=1'], ['port' => '3306'], ['port' => 0], ['drop_existing' => true]] as $change) {
        $suite->throws(fn () => $validator->database(array_replace($database, $change)), BusinessException::class, 42200);
    }
});

$suite->test('网页安装保留密码原字节且复用管理员确认规则', function () use ($suite): void {
    $validator = new InstallValidator();
    $database = ['host' => 'db.example', 'port' => 3306, 'name' => 'mpay_fixture', 'user' => 'fixture_user', 'pass' => ' #;\\"=false '];
    $admin = ['username' => 'fixture_admin', 'password' => 'synthetic-password-2026', 'password_confirmation' => 'synthetic-password-2026'];
    $result = $validator->installation(['database' => (object) $database, 'administrator' => (object) $admin]);
    $suite->same($database['pass'], $result['database']['pass']);
    $suite->same('默认应用', $result['administrator']['app_name']);
    $admin['password_confirmation'] = 'different-value';
    $suite->throws(fn () => $validator->installation(['database' => (object) $database, 'administrator' => (object) $admin]), BusinessException::class, 42200);
});

$suite->test('网页安装拒绝数组正文和额外重置开关', function () use ($suite): void {
    $validator = new InstallValidator();
    $suite->throws(fn () => $validator->installation(['database' => [], 'administrator' => []]), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->installation(['database' => new stdClass(), 'administrator' => new stdClass(), 'overwrite' => true]), BusinessException::class, 42200);
});

$suite->test('MySQL只检查最低版本不锁定补丁或上限', function () use ($suite): void {
    foreach (['5.7.9', '5.7.44-log', '8.0.40', '8.4.3', '9.0.1'] as $version) {
        MigrationService::assertVersion($version);
        $suite->true(true);
    }
    foreach (['5.6.51', '5.7.8', '10.11.6-MariaDB', 'unknown'] as $version) {
        $suite->throws(fn () => MigrationService::assertVersion($version), RuntimeException::class);
    }
});


$suite->test('建表步骤直接保留 SQL 原文及摘要', function () use ($suite): void {
    $sql = 'CREATE TABLE ma_fixture (id BIGINT PRIMARY KEY) ENGINE=InnoDB';
    $step = MigrationStep::fromDefinition('202610080100_fixture', 'ma_fixture', $sql);
    $suite->same($sql, $step->sql);
    $suite->same(hash('sha256', $sql), $step->checksum);
    $suite->same('202610080100_fixture:ma_fixture', $step->identity());
    $changed = MigrationStep::fromDefinition('202610080100_fixture', 'ma_fixture', str_replace('BIGINT', 'INT', $sql));
    $suite->true($changed->checksum !== $step->checksum);
});

$suite->test('建表计划拒绝错表、多语句和未实现的升级类型', function () use ($suite): void {
    foreach (['CREATE TABLE ma_other (id INT)', 'CREATE TABLE ma_fixture (id INT); DROP TABLE ma_other',
        ['type' => 'alter', 'sql' => 'ALTER TABLE ma_fixture ADD extra INT'],
        static fn () => null] as $invalid) {
        $suite->throws(fn () => MigrationStep::fromDefinition('202610080100_fixture', 'ma_fixture', $invalid), RuntimeException::class);
    }
    $suite->throws(fn () => MigrationStep::fromDefinition('invalid-version', 'ma_fixture', 'CREATE TABLE ma_fixture (id INT)'), RuntimeException::class);
});

$suite->test('纯注释迁移拒绝结构参数并固定声明摘要', function () use ($suite): void {
    $definition = ['table' => 'ma_fixture', 'comments' => ['comment' => '测试表', 'columns' => ['id' => '内部编号']]];
    $step = MigrationStep::fromDefinition('202610100200_fixture', 'ma_fixture_comments', $definition);
    $suite->same('', $step->sql);
    $suite->same($definition['comments'], $step->comments);
    $changed = $definition; $changed['comments']['columns']['id'] = '修改说明';
    $suite->true($step->checksum !== MigrationStep::fromDefinition('202610100200_fixture', 'ma_fixture_comments', $changed)->checksum);
    foreach ([$definition + ['sql' => 'DROP TABLE ma_fixture'],
        ['table' => 'other_app', 'comments' => $definition['comments']],
        ['table' => 'ma_fixture', 'comments' => ['comment' => '测试', 'columns' => ['bad`name' => '编号']]],
        ['table' => 'ma_fixture', 'comments' => ['comment' => '测试', 'columns' => ['id' => "多行\n说明"]]],
        ['table' => 'ma_fixture', 'comments' => ['comment' => '测试', 'columns' => ['id' => '']]],
        ['table' => 'ma_fixture', 'comments' => ['comment' => '测试', 'columns' => ['id' => str_repeat('字', 400)]]],
    ] as $invalid) {
        $suite->throws(fn () => MigrationStep::fromDefinition('202610100200_fixture', 'ma_fixture_comments', $invalid), RuntimeException::class);
    }
});
