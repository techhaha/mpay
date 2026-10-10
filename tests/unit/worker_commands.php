<?php
declare(strict_types=1);

namespace app\command {
    /** 仅本夹具启用虚拟单调时钟，跨过旧的一小时期限；其他命令调用仍使用 PHP 原生时钟。 */
    function hrtime(bool $asNumber = false): array|int|float
    {
        if (!isset($GLOBALS['mpay_worker_clock_calls'])) { return \hrtime($asNumber); }
        $seconds = ++$GLOBALS['mpay_worker_clock_calls'] <= 2 ? 0 : 3601;
        return $asNumber ? $seconds * 1000000000 : [$seconds, 0];
    }
}

namespace {
    /**
     * 使用真实命令解析/循环及角色锁，业务依赖全部替换，不连接数据库或发送通知。
     * 第二次业务调用故意抛异常结束默认常驻测试，避免等待实际一小时或留下后台进程。
     * @return array{code:int,calls:int,error:?string}
     */
    $runWorker = function (string $class, array $arguments) use ($app): array {
        $directory = $app->getRuntimePath() . 'worker-command-' . bin2hex(random_bytes(8));
        $probe = new class {
            public int $calls = 0;
            public ?Throwable $error = null;
            public function dispatchOne(string $owner): string { return $this->next('acknowledged'); }
            public function collectOne(string $owner): string { return $this->next('completed'); }
            private function next(string $result): string {
                if (++$this->calls === 2) { throw new RuntimeException('synthetic_worker_stop'); }
                return $result;
            }
            public function exception(Throwable $error): void { $this->error = $error; }
            public function save(): void {}
            public function runOnce(int $limit, int $seconds, string $phase): array {
                return ['expired' => 0, 'executions_checked' => 0, 'released' => 0];
            }
        };
        try {
            $isolated = new think\App($app->getRootPath());
            $isolated->setRuntimePath($directory . '/');
            foreach (['log', app\common\util\SafeLogger::class,
                app\service\payment\notify\MerchantNotifyDispatcherService::class,
                app\service\payment\runtime\PaymentRuntimeMaintenanceService::class,
                app\service\payment\collection\ReceiptWatcherService::class] as $service) {
                $isolated->instance($service, $probe);
            }
            $GLOBALS['mpay_worker_clock_calls'] = 0;
            $command = new $class();
            $command->setApp($isolated);
            $code = $command->run(new think\console\Input($arguments), new think\console\Output('buffer'));
            return ['code' => $code, 'calls' => $probe->calls, 'error' => $probe->error?->getMessage()];
        } finally {
            unset($GLOBALS['mpay_worker_clock_calls']);
            think\App::setInstance($app);
            // 仅删除本次随机测试目录内由命令生成的锁和心跳，保留其他测试/站点运行文件。
            foreach (glob($directory . '/workers/*') ?: [] as $file) { unlink($file); }
            if (is_dir($directory . '/workers')) { rmdir($directory . '/workers'); }
            if (is_dir($directory)) { rmdir($directory); }
        }
    };
    $workers = [app\command\PaymentWatchCommand::class, app\command\PaymentWorkCommand::class];

    $suite->test('两个守护命令无参数时持续处理且不受旧一小时期限限制', function () use ($suite, $runWorker, $workers): void {
        foreach ($workers as $class) {
            $suite->same(['code' => 1, 'calls' => 2, 'error' => 'synthetic_worker_stop'], $runWorker($class, []));
        }
    });
    $suite->test('显式运行期限在已处理轮次之后正常退出', function () use ($suite, $runWorker, $workers): void {
        foreach ($workers as $class) {
            $suite->same(['code' => 0, 'calls' => 1, 'error' => null], $runWorker($class, ['--max-seconds=60']));
        }
    });
    $suite->test('单次批次不依赖运行期限且保留数量上限', function () use ($suite, $runWorker, $workers): void {
        foreach ($workers as $class) {
            $suite->same(['code' => 0, 'calls' => 1, 'error' => null], $runWorker($class, ['--once', '--limit=1']));
        }
    });
    $suite->test('可选运行期限仍拒绝非法值且不处理业务', function () use ($suite, $runWorker, $workers): void {
        foreach ($workers as $class) {
            foreach (['0', '59', '86401', '1e3'] as $seconds) {
                $result = $runWorker($class, ['--max-seconds=' . $seconds]);
                $suite->same(1, $result['code']);
                $suite->same(0, $result['calls']);
            }
        }
    });
}
