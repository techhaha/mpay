<?php
declare(strict_types=1);

use app\common\util\Money;
use app\common\util\RequestContext;
use app\http\admin\validation\PaginationValidator;
use app\exception\BusinessException;

$suite->test('金额精度、上限及溢出', function () use ($suite): void {
    foreach (['0' => 0, '0.01' => 1, '10.03' => 1003, '1.1' => 110, '9999999.99' => Money::MAX_CENTS] as $yuan => $cents) {
        $suite->same($cents, Money::parse((string) $yuan));
        $suite->same($cents, Money::parse(Money::format($cents)));
    }
    foreach (['-1', '+1', ' 1', '1 ', '1e2', '.01', '1.', '01', '1.001', 'NaN', '10000000', str_repeat('9', 80)] as $invalid) {
        $suite->throws(fn () => Money::parse($invalid), InvalidArgumentException::class);
    }
    $suite->same(1003, Money::add(1000, 3));
    $suite->throws(fn () => Money::add(Money::MAX_CENTS, 1), InvalidArgumentException::class);
    $suite->throws(fn () => Money::format(-1), InvalidArgumentException::class);
});

$suite->test('分页拒绝非法形态和超限', function () use ($suite): void {
    $validator = new PaginationValidator();
    $suite->same(['page' => 1, 'limit' => 20], $validator->validate([]));
    $suite->same(['page' => 2, 'limit' => 100], $validator->validate(['page' => '2', 'limit' => '100']));
    foreach ([['page' => 0], ['page' => []], ['page' => 1.5], ['limit' => 101], ['limit' => '1e2']] as $input) {
        $e = $suite->throws(fn () => $validator->validate($input), BusinessException::class, 42200);
        $suite->true($e->errors !== []);
    }
});

$suite->test('请求关联号与模型公开隐藏字段', function () use ($suite): void {
    $one = new RequestContext(); $two = new RequestContext();
    $suite->same(1, preg_match('/^[a-f0-9]{32}$/', $one->id()));
    $suite->true($one->id() !== $two->id());
    $model = new app\model\payment\PaymentAppKey(['id' => '9007199254740993', 'version' => '2', 'secret' => 'hidden']);
    $data = $model->toArray();
    $suite->same('9007199254740993', $data['id']);
    $suite->same(2, $data['version']);
    $suite->true(!array_key_exists('secret', $data));
});

$suite->test('Session 重建、CSRF 和失效', function () use ($suite, $app): void {
    $session = $app->session;
    $session->init();
    $service = new app\service\system\access\AdminSessionService($session);
    $oldId = $session->getId();
    $oldToken = $service->csrfToken();
    $service->establish('1', 2);
    $suite->true($oldId !== $session->getId());
    $suite->true($oldToken !== $service->csrfToken());
    $middleware = new app\http\middleware\CsrfMiddleware($session);
    $request = (new think\Request())->setMethod('POST')->withServer(['REQUEST_METHOD' => 'POST'])->withHeader(['x-csrf-token' => $service->csrfToken()]);
    $suite->same(200, $middleware->handle($request, fn () => response('ok'))->getCode());
    $request->withHeader(['x-csrf-token' => $oldToken]);
    $suite->throws(fn () => $middleware->handle($request, fn () => response('bad')), BusinessException::class, 40310);
    $service->invalidate();
    $suite->same(null, $session->get('admin'));
});

$suite->test('日志只记录允许的结构字段', function () use ($suite, $app): void {
    $app->config->set(['default' => 'test', 'channels' => ['test' => ['type' => 'File', 'path' => $app->getRuntimePath() . 'safe-log/', 'realtime_write' => false]]], 'log');
    $log = new think\Log($app);
    $logger = new app\common\util\SafeLogger($log, new RequestContext());
    $logger->record('test.redaction', ['pay_no' => 'P-fixture', 'password' => 'LEAKME', 'raw_response' => 'LEAKME']);
    $logger->exception(new RuntimeException('LEAKME SQL and secret'));
    $log->save();
    $records = '';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app->getRuntimePath() . 'safe-log/', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) { $records .= file_get_contents($file->getPathname()); }
    }
    $suite->true(!str_contains($records, 'LEAKME'));
    $suite->true(str_contains($records, 'P-fixture'));
    $log->save();
});
