<?php
declare(strict_types=1);

// 父夹具创建的隔离库内竞争同一日限额；不读取业务 .env 或真实凭证。
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--isolated-channel-worker') { exit(2); }
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';require __DIR__.'/Database.php';
try {
    $file=realpath($argv[2]??'')?:throw new RuntimeException('缺少父夹具');
    if(!str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',$root).'/runtime/tests/'))throw new RuntimeException('夹具路径越界');
    $input=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);$index=(int)($argv[3]??-1);
    if(!isset($input['apps'][$index]))throw new RuntimeException('进程编号无效');
    $app=new think\App($root);$app->setEnvName('test-disabled');$app->setRuntimePath($root.'/runtime/tests/');$app->initialize();
    TestDatabase::fromEnvironment()->bindApplication($app,$input['database']);
    $application=$app->make(app\repository\payment\config\PaymentAppRepository::class)->find($input['apps'][$index]);
    $key=$app->make(app\service\payment\config\PaymentAppCredentialService::class)->secretForVersion($application['id'],$application['active_key_id']);
    $raw=['pid'=>$application['pid'],'type'=>'wxpay','out_trade_no'=>'quota-concurrent-'.$index,'name'=>'限额并发夹具','money'=>'10.00','notify_url'=>'https://merchant.example/notify','return_url'=>'https://merchant.example/return','clientip'=>'127.0.0.1','sign_type'=>'MD5'];
    $raw['sign']=$app->make(app\service\payment\epay\EpayV1Signer::class)->sign($raw,$key);
    $app->request->withServer(['HTTP_HOST'=>'pay.example','HTTPS'=>'on','SERVER_PORT'=>'443']);
    file_put_contents($file.'.ready.'.getmypid(),'ready');$deadline=microtime(true)+15;
    while(!is_file($file.'.start')){if(microtime(true)>$deadline)throw new RuntimeException('并发屏障超时');usleep(1000);}
    try{$app->make(app\service\payment\epay\EpayV1ProtocolService::class)->create($raw,'mapi','127.0.0.1','pc',bin2hex(random_bytes(16)));$accepted=true;}
    catch(app\exception\BusinessException $e){if($e->getCode()!==50300)throw $e;$accepted=false;}
    echo json_encode(['accepted'=>$accepted],JSON_THROW_ON_ERROR);
} catch(Throwable $e){$driver=$e instanceof think\db\exception\PDOException?($e->getData()['PDO Error Info']['Driver Error Code']??'unknown'):'';fwrite(STDERR,$e::class.':'.basename($e->getFile()).':'.$e->getLine().':'.$driver.' '.implode(' > ',array_map(static fn(array $frame):string=>($frame['class']??'').'::'.$frame['function'],array_slice($e->getTrace(),0,16))));exit(1);}
