<?php
declare(strict_types=1);

// 仅由并发夹具启动；连接由显式隔离配置和父进程的随机库名决定，不读取业务 .env。
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--isolated-number-worker') { exit(2); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Database.php';
try {
    $pdo = TestDatabase::fromEnvironment()->connect($argv[2] ?? '');
    // 所有进程先撞同一个已提交编号，再切回系统随机源；实际验证 MySQL 的索引报文识别。
    $engine = new class implements Random\Engine {
        private bool $first = true;
        public function generate(): string
        {
            if ($this->first) { $this->first = false; return str_repeat("\0", 8); }
            return random_bytes(8);
        }
    };
    $numbers = new app\common\util\BusinessNumber(new Random\Randomizer($engine));
    $time = new DateTimeImmutable('2026-10-09 15:30:45', new DateTimeZone('Asia/Shanghai'));
    $insert = $pdo->prepare('INSERT INTO fixture_number (number) VALUES (?)');
    $collisions = 0;
    for ($index = 0; $index < 100; $index++) {
        $numbers->insert('B', $time, function (string $number) use ($insert, &$collisions): void {
            try { $insert->execute([$number]); }
            catch (PDOException $exception) { $collisions++; throw new think\db\exception\PDOException($exception); }
        });
    }
    echo json_encode(['inserted' => 100, 'collisions' => $collisions], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    // 驱动报文可能包含连接信息，只输出类型和代码位置，不输出 SQL/绑定值。
    fwrite(STDERR, $exception::class . ':' . basename($exception->getFile()) . ':' . $exception->getLine());
    exit(1);
}
