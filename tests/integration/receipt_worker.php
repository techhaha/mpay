<?php
declare(strict_types=1);

// 只消费父夹具的随机隔离库；不读取业务 .env。两个进程先选中同一批订单，再一起竞争成功事务。
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--isolated-receipt-worker') { exit(2); }
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require __DIR__ . '/Database.php';
try {
    $file = realpath($argv[2] ?? '') ?: throw new RuntimeException('缺少父夹具');
    if (!str_starts_with(str_replace('\\','/',$file), str_replace('\\','/',$root).'/runtime/tests/')) { throw new RuntimeException('夹具路径越界'); }
    $input = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    $app = new think\App($root);$app->setEnvName('test-disabled');$app->setRuntimePath($root.'/runtime/tests/');$app->initialize();
    TestDatabase::fromEnvironment()->bindApplication($app,$input['database']);
    $configuration=$app->make(app\repository\payment\config\PaymentPluginConfRepository::class)->find($input['configuration']);
    $scopes=$app->db->transaction(fn()=>$app->make(app\service\payment\receipt\ReceiptScopeService::class)->describe(
        $app->make(app\service\payment\receipt\ReceiptScopeService::class)->current($configuration,true)));
    $plugin=$app->make(app\service\payment\plugin\PaymentPluginFactoryService::class)->initialize($configuration['plugin_code'],$configuration['config_json'],[]);
    $results=$plugin->matchReceipts($input['batch']);
    file_put_contents($file.'.ready.'.getmypid(),'ready');
    $deadline=microtime(true)+10;
    while(!is_file($file.'.start')){if(microtime(true)>$deadline){throw new RuntimeException('并发屏障超时');}usleep(1000);}
    $context=new app\service\payment\receipt\ChannelNotifyContext(null,$configuration['id'],$configuration['account_key'],(int)$configuration['config_version'],
        $configuration['plugin_code'],$plugin->getVersion(),$scopes,$configuration['config_json'],new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')));
    $matched=0;
    foreach($results as $result){$matched+=(int)$app->make(app\service\payment\receipt\PayOrderCallbackService::class)->acceptPluginResult($result,$context,bin2hex(random_bytes(16)))['matched'];}
    echo json_encode(['selected'=>count($results),'matched'=>$matched],JSON_THROW_ON_ERROR);
} catch(Throwable $error){fwrite(STDERR,$error::class.':'.basename($error->getFile()).':'.$error->getLine());exit(1);}
