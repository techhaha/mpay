<?php
declare(strict_types=1);

use app\common\util\Clock;
use app\service\payment\config\PaymentChannelEditorService;
use app\service\payment\config\PaymentConfigurationQueryService;
use app\service\system\audit\ConfigurationActionContext;
use app\service\payment\collection\ReceiptWatcherService;

require_once __DIR__.'/Database.php';
require_once dirname(__DIR__).'/fixtures/CountingReceiptHttp.php';
$database=TestDatabase::fromEnvironment();$previousApp=$app;
$app=new think\App(dirname(__DIR__,2));$app->setEnvName('test-disabled');$app->setRuntimePath(dirname(__DIR__,2).'/runtime/tests/collector/');$app->initialize();$app->config->set(['environment'=>'testing'],'mpay');
$clock=new class extends Clock { public int $offset=0;public function now(): DateTimeImmutable{return parent::now()->modify('+'.$this->offset.' seconds');} };
$http=new CountingReceiptHttp();$app->instance(Clock::class,$clock);$app->instance(app\common\util\HttpClient::class,$http);
$actor=ConfigurationActionContext::cli();$channels=[];$settings=['watcher_username'=>'fixture-login','watcher_password'=>'fixture-password','receipt_account_no'=>'store-a','receipt_qrcode_content'=>'https://example.invalid/code'];
$rules=['name'=>'采集通道甲','minimum_amount'=>1,'maximum_amount'=>100000,'daily_limit_amount'=>0,'daily_limit_count'=>0,'available_from'=>null,'available_to'=>null];
try {
    $suite->test('通道独立配置且同账号查询及会话归并',function()use($suite,$app,$database,$actor,&$channels,$settings,$rules):void{
        [$name]=$database->create();$pdo=$database->bindApplication($app,$name);
        (new app\service\system\install\MigrationService($pdo))->migrate($app->getRootPath().'database/migrations');
        $app->make(app\service\system\install\InitializationService::class)->initialize('collector_admin','Synthetic-Only-2086','采集测试','99001');
        $editor=$app->make(PaymentChannelEditorService::class);
        foreach(['甲','乙']as$name){$rules['name']='采集通道'.$name;$channels[]=$editor->create(['plugin_code'=>'shouqianba_receipt','pay_type'=>'alipay','settings'=>$settings,'channel'=>$rules,'enabled'=>true],$actor);}
        $suite->true($channels[0]['configuration_id']!==$channels[1]['configuration_id']);
        $suite->same(1,(new app\model\payment\ReceiptRuntimeState())->count());$suite->same(1,(new app\model\payment\ReceiptSession())->count());
        $query=$app->make(PaymentConfigurationQueryService::class);$a=$query->channel($channels[0]['id']);$b=$query->channel($channels[1]['id']);
        $suite->same($a['channel']['scope_key'],$b['channel']['scope_key']);
        $editor->configure(['id'=>$a['id'],'expected_revision'=>$a['revision_id'],'expected_configuration_version'=>1,'settings'=>$settings+['receipt_valid_seconds'=>600],'retain_secrets'=>[]],$actor);
        $suite->same(true,$query->channel($b['id'])['enabled']);$suite->same(1,$query->channel($b['id'])['configuration_version']);
        $a=$query->channel($a['id']);$editor->setEnabled(['id'=>$a['id'],'expected_revision'=>$a['revision_id'],'expected_configuration_version'=>2,'enabled'=>true],$actor);
    });
    $suite->test('真实 SDK 协议合成统计一次登录一次查询并批量确认两通道订单',function()use($suite,$app,$http,$clock,&$channels):void{
        $apps=$app->make(app\repository\payment\config\PaymentAppRepository::class);$application=$apps->all()[0];
        $key=$app->make(app\service\payment\config\PaymentAppCredentialService::class)->secretForVersion($application['id'],$application['active_key_id']);
        $app->make(app\service\payment\config\PaymentAppService::class)->save($application['id'],(int)$application['revision'],$application['name'],$application['pid'],true,['merchant.example'],ConfigurationActionContext::cli());
        $app->request->withServer(['HTTP_HOST'=>'pay.example','HTTPS'=>'on','SERVER_PORT'=>'443']);$pays=[];
        foreach([1,2]as$i){
            $raw=['pid'=>$application['pid'],'type'=>'alipay','out_trade_no'=>'collector-'.$i,'name'=>'合成订单','money'=>'12.00','notify_url'=>'https://merchant.example/notify','return_url'=>'https://merchant.example/return','clientip'=>'127.0.0.1','sign_type'=>'MD5'];
            $raw['sign']=$app->make(app\service\payment\epay\EpayV1Signer::class)->sign($raw,$key);
            $created=$app->make(app\service\payment\epay\EpayV1ProtocolService::class)->create($raw,'mapi','127.0.0.1','pc',bin2hex(random_bytes(16)));
            $pay=$app->make(app\repository\payment\trade\PayOrderRepository::class)->find($created['trade_no']);$pays[]=$pay;
            $http->rows[]=['status'=>0,'payway'=>2,'merchant_sn'=>'merchant-fixture','store_sn'=>'store-a','original_amount'=>$pay->receiptAmount(),'order_sn'=>'synthetic-collector-'.$i,'finish_time'=>time(),'reflect'=>''];
        }
        $suite->true($pays[0]['channel_id']!==$pays[1]['channel_id']);$suite->true($pays[0]->receiptAmount()!==$pays[1]->receiptAmount());
        $clock->offset=5;$watch=$app->make(ReceiptWatcherService::class);$suite->same('completed',$watch->collectOne(bin2hex(random_bytes(16))));
        $suite->same(1,$http->logins);$suite->same(1,$http->queries);
        foreach($pays as$pay)$suite->same(2,$app->make(app\repository\payment\trade\PayOrderRepository::class)->find($pay['pay_no'])['status']);
        $suite->same(2,(new app\model\payment\ReceiptFlow())->count());
        (new app\model\payment\ReceiptRuntimeState())->where('id','>',0)->update(['next_run_at'=>date('Y-m-d H:i:s')]);
        $suite->same('completed',$watch->collectOne(bin2hex(random_bytes(16))));$suite->same(1,$http->logins);$suite->same(2,$http->queries);$suite->same(2,(new app\model\payment\ReceiptFlow())->count());
    });
    $suite->test('不同门店复用登录会话并阻止并发切换登录上下文',function()use($suite,$app,$http,$settings,$rules,$actor):void{
        $second=$settings;$second['receipt_account_no']='store-b';$rules['name']='门店乙';
        $app->make(PaymentChannelEditorService::class)->create(['plugin_code'=>'shouqianba_receipt','pay_type'=>'alipay','settings'=>$second,'channel'=>$rules,'enabled'=>true],$actor);
        $watch=$app->make(ReceiptWatcherService::class);$http->duringQuery=function()use($suite,$watch):void{$suite->same('idle',$watch->collectOne(bin2hex(random_bytes(16))));};
        $suite->same('completed',$watch->collectOne(bin2hex(random_bytes(16))));$suite->same(1,$http->logins);$suite->true(in_array('store-b',$http->stores,true));
        $suite->same(2,(new app\model\payment\ReceiptRuntimeState())->count());$suite->same(1,(new app\model\payment\ReceiptSession())->count());
    });
    $suite->test('同登录账号密码冲突允许保存但不交替登录且过期租约可接管',function()use($suite,$app,$http,$settings,$rules,$actor,&$channels):void{
        $bad=$settings;$bad['watcher_password']='another-fixture-password';$rules['name']='凭证冲突通道';
        $created=$app->make(PaymentChannelEditorService::class)->create(['plugin_code'=>'shouqianba_receipt','pay_type'=>'alipay','settings'=>$bad,'channel'=>$rules,'enabled'=>true],$actor);
        $before=$http->queries;$watch=$app->make(ReceiptWatcherService::class);
        $suite->same('deferred',$watch->collectOne(bin2hex(random_bytes(16))));$suite->same($before,$http->queries);$suite->same(1,$http->logins);
        $detail=$app->make(PaymentConfigurationQueryService::class)->channel($created['id']);
        $app->make(PaymentChannelEditorService::class)->setEnabled(['id'=>$created['id'],'expected_revision'=>$detail['revision_id'],'expected_configuration_version'=>1,'enabled'=>false],$actor);
        $expired=date('Y-m-d H:i:s',time()-60);(new app\model\payment\ReceiptSession())->where('id','>',0)->update(['claim_token'=>str_repeat('a',32),'lease_until'=>$expired]);
        (new app\model\payment\ReceiptRuntimeState())->where('id','>',0)->update(['claim_token'=>str_repeat('a',32),'lease_until'=>$expired,'next_run_at'=>$expired]);
        $suite->same('completed',$watch->collectOne(bin2hex(random_bytes(16))));$suite->same(1,$http->logins);
    });
    $suite->test('修改二维码保留登录会话且不会停用另一个通道',function()use($suite,$app,$http,$settings,$actor,&$channels):void{
        $query=$app->make(PaymentConfigurationQueryService::class);$detail=$query->channel($channels[0]['id']);
        $before=(new app\model\payment\ReceiptSession())->where('id','>',0)->find()['session_json'];
        $changed=$settings;$changed['receipt_qrcode_content']='https://example.invalid/replaced-qr';
        $app->make(PaymentChannelEditorService::class)->configure(['id'=>$detail['id'],'expected_revision'=>$detail['revision_id'],'expected_configuration_version'=>$detail['configuration_version'],'settings'=>$changed,'retain_secrets'=>[]],$actor);
        $suite->same($before,(new app\model\payment\ReceiptSession())->where('id','>',0)->find()['session_json']);
        $suite->same(true,$query->channel($channels[1]['id'])['enabled']);
        $suite->same('completed',$app->make(ReceiptWatcherService::class)->collectOne(bin2hex(random_bytes(16))));$suite->same(1,$http->logins);
    });
} finally {$database->cleanup();think\Container::setInstance($previousApp);$app=$previousApp;}
