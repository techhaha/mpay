<?php
declare(strict_types=1);

// 测试入口不作为 Web 路由使用，避免网络请求触发隔离库建删或故障注入。
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// 先解析清单再启动框架；列出/筛选未命中不读取业务环境或创建测试库。
require __DIR__ . '/Selection.php';
try {
    $selection = new TestSelection(array_slice($argv, 1));
    echo json_encode(['module' => $selection->module, 'integration' => $selection->integration,
        'filter' => $selection->filter, 'selection_unit' => 'fixture_file', 'files' => $selection->files],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    if ($selection->list) { exit(0); }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
// 仅明确的 CLI 隔离测试入口允许加载模拟插件；生产环境配置不能单独打开该能力。
define('MPAY_ISOLATED_TEST', true);
require __DIR__ . '/Suite.php';
require __DIR__ . '/Report.php';
$suite = new Suite();
$runReport = new TestReport(dirname(__DIR__), 'tests/run.php', ['module' => $selection->module,
    'integration' => $selection->integration, 'filter' => $selection->filter, 'selection_unit' => 'fixture_file', 'files' => $selection->files],
    getenv('MPAY_TEST_SNAPSHOT') ?: null);
$runFailure = null;
try {
    $runReport->assertReady();
    require dirname(__DIR__) . '/vendor/autoload.php';

    $app = new think\App(dirname(__DIR__));
    // 使用独立环境名和 runtime，避免加载业务 .env；不要创建 .env.test-disabled 作为真实配置。
    $app->setEnvName('test-disabled');
    $app->setRuntimePath(dirname(__DIR__) . '/runtime/tests/');
    $app->initialize();
    $app->config->set(['environment' => 'testing'], 'mpay');
    // 只 require 选中的完整夹具单元，保持同一集成场景的前序事实。
    foreach (array_keys($selection->files) as $testFile) {
        require __DIR__ . '/' . $testFile;
    }
    if ($suite->cases === 0) { throw new RuntimeException('未实际执行任何用例'); }
} catch (Throwable $e) {
    $runFailure = $e;
    fwrite(STDERR, 'FAIL ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n");
}
try {
    $saved = $runReport->finish($suite, $runFailure, class_exists(TestDatabase::class, false) ? TestDatabase::cleanupSummary() : []);
    echo 'REPORT ' . $saved['path'] . "\n";
    if ($saved['status'] !== 'passed') { exit(1); }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL REPORT ' . $e::class . "\n");
    exit(1);
}
echo "OK {$suite->cases} cases, {$suite->assertions} assertions\n";
