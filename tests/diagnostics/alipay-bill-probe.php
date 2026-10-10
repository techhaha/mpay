<?php
declare(strict_types=1);

// 开发人员显式运行的只读平台探测；不注册正式命令或 Web 路由。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc === 2 && $argv[1] === '--help') {
    echo "php tests/diagnostics/alipay-bill-probe.php --input=<public 目录之外的私有 JSON 文件>\n";
    exit(0);
}
if ($argc !== 2 || !str_starts_with($argv[1], '--input=') || strlen($argv[1]) === 8) {
    fwrite(STDERR, "请使用 --help 查看输入方式。\n");
    exit(2);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/AlipayBillProbe.php';
require __DIR__ . '/AlipayBillProbeValidator.php';
$app = new think\App(dirname(__DIR__, 2));
// 不读取站点凭证或连接业务数据库，平台参数只来自本次显式指定的私有文件。
$app->setEnvName('test-disabled');
$app->setRuntimePath(dirname(__DIR__, 2) . '/runtime/diagnostics/');
try {
    $app->initialize();
    $raw = $app->make(app\command\ConfigurationInputFile::class)->read(substr($argv[1], 8));
    $data = (new tests\diagnostics\AlipayBillProbeValidator())->validateInput($raw);
    $probe = new tests\diagnostics\AlipayBillProbe($app->make(app\common\sdk\alipay\AlipayBillClient::class), $app->make(app\common\util\Clock::class));
    echo json_encode($probe->inspect($data['account'], $data['from_timestamp'], $data['to_timestamp'],
        $data['max_pages'], $data['budget_seconds']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
} catch (app\exception\BusinessException|InvalidArgumentException) {
    fwrite(STDERR, "探测输入无效，请核对私有文件、账号、密钥、时间窗口和预算。\n");
    exit(2);
} catch (app\exception\ReceiptCollectionException $error) {
    echo json_encode(['status' => 'failed', 'reason' => $error->reasonCode, 'platform_acceptance' => 'not_attested'], JSON_THROW_ON_ERROR) . "\n";
    exit(3);
} catch (Throwable $error) {
    // 只记录脱敏诊断，不把原平台响应或输入值拼接进输出。
    $app->make(app\common\util\SafeLogger::class)->exception($error);
    fwrite(STDERR, "账单检查未完成，请查看诊断日志。\n");
    exit(1);
}
