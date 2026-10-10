<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
$suite->test('四进程同秒编号碰撞重试与数据库事务回滚', function () use ($suite): void {
    $database = TestDatabase::fromEnvironment(); $workers = [];
    try {
        [$name, $pdo] = $database->create();
        $pdo->exec('CREATE TABLE fixture_number (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number CHAR(20) NOT NULL,
            UNIQUE KEY uk_biz_no (number)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO fixture_number (number) VALUES ('B2026100915304500000')");
        for ($index = 0; $index < 4; $index++) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/number_worker.php', '--isolated-number-worker', $name],
                [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('无法启动隔离并发进程'); }
            fclose($pipes[0]); $workers[] = [$process, $pipes];
        }
        foreach ($workers as [$process, $pipes]) {
            $result = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $suite->same(0, proc_close($process)); $suite->same('', $error);
            $values = json_decode($result, true, 16, JSON_THROW_ON_ERROR);
            $suite->same(100, $values['inserted']); $suite->true($values['collisions'] >= 1);
        }
        $suite->same(401, (int) $pdo->query('SELECT COUNT(DISTINCT number) FROM fixture_number')->fetchColumn());
        $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM fixture_number WHERE number NOT REGEXP '^B20261009153045[0-9]{5}$'")->fetchColumn());
        $numbers = new app\common\util\BusinessNumber();
        $pdo->beginTransaction();
        $numbers->insert('B', new DateTimeImmutable('2026-10-09 15:30:46'), function (string $number) use ($pdo): void {
            $pdo->prepare('INSERT INTO fixture_number (number) VALUES (?)')->execute([$number]);
        });
        $pdo->rollBack();
        $suite->same(401, (int) $pdo->query('SELECT COUNT(*) FROM fixture_number')->fetchColumn());
    } finally {
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
        }
        $database->cleanup();
    }
});
