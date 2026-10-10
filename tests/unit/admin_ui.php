<?php
declare(strict_types=1);

use app\exception\BusinessException;
use app\service\payment\config\PaymentConfigurationValidator;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\system\install\MigrationStep;
use app\http\admin\validation\OperationsQueryValidator;

// 本组验证后台输入合同、迁移声明及本机运行投影，不连接业务数据库或提交真实通道配置。
$suite->test('工作台待处理筛选保留应用且不隐藏历史记录', function () use ($suite, $app): void {
    $validator = $app->make(OperationsQueryValidator::class);
    $input = $validator->listing('notifications', ['app_id' => '1', 'status' => '0', 'all_dates' => '1']);
    $suite->same(['app_id' => '1', 'status' => 0], $input['filters']);
    $suite->same(20, $input['limit']);
    $suite->true(isset($validator->listing('notifications', [])['filters']['from']));
    $suite->throws(fn () => $validator->listing('receipts', ['status'=>'2']), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->listing('audit', ['all_dates' => '1']), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->listing('notifications', ['all_dates' => true]), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->listing('notifications', ['app_id' => 'invalid']), BusinessException::class, 42200);
});

$suite->test('订单商户通知筛选区分暂缓、调度和历史确认', function () use ($suite, $app): void {
    $validator = $app->make(\app\http\admin\validation\PayOrderValidator::class);
    foreach (['none','0','1','2','3','4','acknowledged'] as $status) {
        $suite->same($status, $validator->filters(['notification_status'=>$status])['filters']['notification_status']);
    }
    $suite->same(null, $validator->filters([])['filters']['notification_status']);
    foreach (['5',false,[],"0 OR 1=1"] as $value) {
        $suite->throws(fn () => $validator->filters(['notification_status'=>$value]), BusinessException::class, 42200);
    }
});

$suite->test('人工确认通知选项必须显式使用布尔值', function () use ($suite, $app): void {
    $validator=$app->make(\app\http\admin\validation\PayOrderValidator::class);
    $input=['biz_no'=>'B2026101012000000001','reason'=>'合成核对','received_amount'=>'1.00','paid_at'=>'2026-10-10 12:00:00',
        'reference'=>'合成凭据','current_password'=>'synthetic-password'];
    foreach([true,false] as $choice){$suite->same($choice,$validator->action('confirm',$input+['notify_merchant'=>$choice])['notify_merchant']);}
    $suite->throws(fn()=>$validator->action('confirm',$input),BusinessException::class,42200);
    foreach(['false',0,1,null,[]] as $choice){$suite->throws(fn()=>$validator->action('confirm',$input+['notify_merchant'=>$choice]),BusinessException::class,42200);}
});

$suite->test('统一通道表单拒绝内部字段和宽松金额类型', function () use ($suite): void {
    $validator = new PaymentConfigurationValidator();
    $channel = ['name' => '合成通道', 'minimum_amount' => 1, 'maximum_amount' => 10000, 'daily_limit_amount'=>0, 'daily_limit_count'=>0, 'available_from'=>null, 'available_to'=>null];
    $input = ['plugin_code' => 'wxpay_receipt', 'pay_type' => 'wxpay',
        'settings' => (object) ['sms_forwarder_secret' => 'synthetic-only-secret'], 'channel' => (object) $channel];
    $suite->same($channel, $validator->validate('editor-create', $input)['channel']);
    $suite->same(true, $validator->validate('editor-create', $input + ['enabled' => true, 'participates_default_route' => true])['enabled']);
    $suite->throws(fn () => $validator->validate('editor-create', $input + ['enabled' => 'true']), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->validate('editor-create', $input + ['participates_default_route' => 1]), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->validate('editor-create', array_replace($input,
        ['channel' => (object) ($channel + ['scope_key' => 'client-controlled'])])), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->validate('editor-create', array_replace($input,
        ['channel' => (object) array_replace($channel, ['minimum_amount' => '1'])])), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->validate('editor-create', $input + ['reuse_configuration_id' => '1']), BusinessException::class, 42200);
});

$suite->test('通道编辑与插件配置保存严格分离', function () use ($suite): void {
    $validator = new PaymentConfigurationValidator();
    $versions = ['id' => '1', 'expected_revision' => '2', 'expected_configuration_version' => 1];
    $channel = (object) ['name' => '合成通道', 'minimum_amount' => 1, 'maximum_amount' => 10000, 'daily_limit_amount'=>0, 'daily_limit_count'=>0, 'available_from'=>null, 'available_to'=>null];
    $suite->same('合成通道', $validator->validate('editor-save', $versions + ['plugin_code'=>'wxpay_receipt','pay_type'=>'wxpay','settings'=>new stdClass(),'channel' => $channel])['channel']['name']);
    $plugin = $versions + ['settings' => new stdClass(), 'retain_secrets' => ['sms_forwarder_secret']];
    $suite->same(['sms_forwarder_secret'], $validator->validate('plugin-save', $plugin)['retain_secrets']);
    $suite->throws(fn () => $validator->validate('editor-save', $plugin + ['channel' => $channel]), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->validate('plugin-save', $plugin + ['channel' => $channel]), BusinessException::class, 42200);
    $suite->throws(fn () => $validator->validate('editor-save', $versions + ['channel' => (object) ['name'=>'合成通道','minimum_amount'=>1,'maximum_amount'=>10000,'valid_seconds'=>300]]), BusinessException::class, 42200);
});

$suite->test('插件不再声明额外收款身份且不虚构云闪付能力', function () use ($suite, $app): void {
    $factory = $app->make(PaymentPluginFactoryService::class);
    foreach ($factory->codes() as $code) {
        $definition = $factory->definition($code);
        $suite->true(!in_array('receipt_account_id', array_column($definition->configSchema, 'field'), true));
        $suite->true(!in_array('unionpay', $definition->payTypes, true));
    }
});

$suite->test('通道删除列迁移不改旧建表摘要且拒绝任意DDL', function () use ($suite): void {
    $definition = ['table' => 'ma_payment_channel', 'column' => 'deleted_at',
        'sql' => 'ALTER TABLE ma_payment_channel ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL'];
    $step = MigrationStep::fromDefinition('202610080200_admin_ui', 'ma_payment_channel_deleted_at', $definition);
    $suite->same('deleted_at', $step->addedColumn);
    $suite->same(hash('sha256', $definition['sql']), $step->checksum);
    $suite->throws(fn () => MigrationStep::fromDefinition('202610080200_admin_ui', 'ma_payment_channel_deleted_at',
        array_replace($definition, ['sql' => 'ALTER TABLE ma_payment_channel DROP COLUMN name'])), RuntimeException::class);
    $sql = 'CREATE TABLE ma_fixture (id BIGINT PRIMARY KEY) ENGINE=InnoDB';
    $original = MigrationStep::fromDefinition('202610080100_fixture', 'ma_fixture', $sql);
    $suite->same(null, $original->addedColumn);
    $suite->same(hash('sha256', $sql), $original->checksum);
});

$suite->test('单轮任务退出后保留最近活动且不误报正在运行', function () use ($suite, $app): void {
    $directory = $app->getRuntimePath() . 'status-' . bin2hex(random_bytes(8));
    $isolatedApp = new think\App($app->getRootPath());
    $isolatedApp->setRuntimePath($directory . '/');
    $control = new app\common\util\CliWorkerControl();
    $status = new app\service\system\runtime\RuntimeStatusService($isolatedApp, new app\common\util\Clock());
    $owner = bin2hex(random_bytes(16));
    try {
        $control->heartbeat($directory, 'work', $owner, true);
        $suite->same(true, $status->workers()['workers'][0]['recent']);
        $control->heartbeat($directory, 'work', $owner, false);
        $worker = $status->workers()['workers'][0];
        $suite->same(false, $worker['recent']);
        $suite->same('任务已退出', $worker['status_label']);
        $suite->true(is_int($worker['observed_at']));
        $suite->same(null, $status->workers()['workers'][1]['observed_at']);
    } finally {
        // App 构造会切换框架全局容器；恢复主测试容器，避免后续 ORM/缓存解析落到未初始化的临时实例。
        think\Container::setInstance($app);
        // 只清理本用例新建的随机目录和明确的心跳文件。
        @unlink($directory . '/workers/work-' . $owner . '.json');
        @rmdir($directory . '/workers');
        @rmdir($directory);
    }
});

$suite->test('CSV 下载声明完整字节数且不接受空正文', function () use ($suite): void {
    $stream = fopen('php://temp', 'w+b');
    $csv = "\xEF\xBB\xBF订单,金额\nDEMO,1.00\n";
    fwrite($stream, $csv);
    rewind($stream);
    $response = new app\http\admin\response\CsvResponse($stream);
    $suite->same((string) strlen($csv), $response->getHeader('Content-Length'));
    $suite->same('text/csv; charset=utf-8', $response->getHeader('Content-Type'));
    unset($response);
    $suite->same(false, is_resource($stream));
    $empty = fopen('php://temp', 'w+b');
    try {
        $suite->throws(fn () => new app\http\admin\response\CsvResponse($empty), InvalidArgumentException::class);
    } finally {
        if (is_resource($empty)) { fclose($empty); }
    }
});

$suite->test('常规操作不接受旧原因字段且特殊动作仍需原因', function () use ($suite, $app): void {
    $apps = new \app\http\admin\validation\PaymentAppValidator();
    $input = ['name'=>'合成','pid'=>'','allowed_hosts'=>[]];
    $suite->same($input, $apps->input('create', $input));
    $suite->throws(fn () => $apps->input('create', $input+['reason'=>'旧字段']), BusinessException::class);
    $suite->throws(fn () => $apps->input('rotate', ['id'=>'1','expected_id'=>'1','password'=>'synthetic','key'=>'']), BusinessException::class);
    $catalog = new \app\http\admin\validation\PaymentCatalogValidator();
    $suite->same([], $catalog->validate('refresh', []));
    $suite->throws(fn () => $catalog->validate('refresh', ['reason'=>'旧字段']), BusinessException::class);
    $settings = new \app\http\admin\validation\SystemSettingsValidator();
    $suite->same([], $settings->save(['group'=>'maintenance','expected_version'=>0,'values'=>(object)[]])['values']);
    $context = \app\service\system\audit\ConfigurationActionContext::administrator('1',1,str_repeat('a',32),null,'127.0.0.1');
    $suite->same(null, $context->reason);
    $orders = $app->make(\app\http\admin\validation\PayOrderValidator::class);
    $suite->throws(fn () => $orders->action('close',['biz_no'=>'B2026100909451864753']), BusinessException::class);
});
$suite->test('分页接受四种条数且上限保持一百', function () use ($suite, $app): void {
    $validator = $app->make(OperationsQueryValidator::class);
    foreach ([10,20,50,100] as $limit) {
        $suite->same($limit, $validator->listing('notifications',['page'=>'2','limit'=>(string)$limit])['limit']);
    }
    $suite->throws(fn () => $validator->listing('notifications',['limit'=>'101']), BusinessException::class);
});

$suite->test('传输正文超限中止且保留有界排查片段', function () use ($suite): void {
    $config=new \think\Config();$config->set(['allowed_ports'=>[443],'response_max_bytes'=>65536],'http');
    $addresses=new class extends \app\common\util\HttpAddressResolver {
        public function resolve(string $host,bool $development=false):array{return ['93.184.216.34'];}
    };
    // Guzzle 的自定义处理器只模拟接收到的大分块，不访问网络；复用真实边界流和 on_stats 分类。
    $client=new \GuzzleHttp\Client(['handler'=>static function($request,$options) use($suite){
        $response=new \GuzzleHttp\Psr7\Response(500,[],$options['sink']);
        $options['on_headers']($response);
        $suite->same(0,$options['sink']->write(str_repeat('synthetic-response-',6000)));
        $options['on_stats'](new \GuzzleHttp\TransferStats($request,$response,0.01,0,['primary_ip'=>'93.184.216.34']));
        return \GuzzleHttp\Promise\Create::promiseFor($response);
    }]);
    $clock=new \app\common\util\Clock();
    $http=new \app\common\util\HttpClient($client,new \app\common\util\HttpTargetPolicy($config,$addresses,['merchant.example']),$addresses,$config,$clock);
    $error=$suite->throws(fn()=>$http->get('https://merchant.example/notify',$clock->now()->modify('+30 seconds')),\app\exception\HttpRequestException::class);
    $suite->same('response_too_large',$error->reasonCode);$suite->same(500,$error->httpStatus);
    $suite->same(4096,strlen($error->responsePrefix()));
    $suite->true(!str_contains($error->getMessage(),'synthetic-response'));
});
