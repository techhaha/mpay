<?php
declare(strict_types=1);

use app\common\util\SiteUrl;
use app\exception\BusinessException;

$suite->test('本站链接跟随当前访问域名与端口，迁移不依赖固定网址或 DNS，忽略转发头伪造', function () use ($suite): void {
    $request = new app\Request();
    $site = new SiteUrl($request);
    foreach ([['pay-a.example', true, 'https://pay-a.example'], ['pay-b.example', true, 'https://pay-b.example'],
        ['restored.example:8443', true, 'https://restored.example:8443'], ['localhost:18082', false, 'http://localhost:18082'],
        ['[::1]:18082', false, 'http://[::1]:18082']] as [$host, $secure, $origin]) {
        $request->withServer(['HTTP_HOST' => $host, 'HTTPS' => $secure ? 'on' : 'off', 'SERVER_PORT' => $secure ? '443' : '18082',
            'REQUEST_SCHEME' => $secure ? 'https' : 'http', 'HTTP_X_FORWARDED_HOST' => 'forged.invalid', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        $suite->same($origin, $site->origin());
        $suite->same($origin . '/cashier/pay?biz_no=B-test&token=0', $site->path('/cashier/pay', ['biz_no' => 'B-test', 'token' => '0']));
    }
    foreach (['', 'user@pay.example', 'pay.example/path', "pay.example\r\nX-Forged: yes", 'pay.example?next=evil', 'pay.example:99999'] as $host) {
        $request->withServer(['HTTP_HOST' => $host, 'SERVER_PORT' => '80', 'HTTPS' => 'off']);
        $suite->throws(fn () => $site->origin(), BusinessException::class, 40000);
    }
});

$suite->test('路由和 CSRF 使用实际 HTTP 方法，覆盖参数不改变路由也不删掉待验签原值', function () use ($suite): void {
    $request = new app\Request();
    $request->withServer(['REQUEST_METHOD' => 'POST', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET'])->withPost(['_method' => 'DELETE']);
    $suite->same('POST', $request->method());
    $suite->same('POST', $request->method(true));
    $suite->same('DELETE', $request->post('_method'));
    $request->withServer(['REQUEST_METHOD' => 'HEAD']);
    $suite->same('HEAD', $request->method());
});
