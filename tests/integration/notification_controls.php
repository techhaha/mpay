<?php
declare(strict_types=1);

use app\common\constant\NotifyConstant as Notice;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\model\payment\NotifyTask;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\order\AdminActionContext;
use app\service\payment\order\ManualReceiptEvidence;
use app\service\payment\order\PayOrderAdminActionService;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\notify\MerchantNotifyDispatcherService;

// Entire fixture owns a random test database; HTTP is counted synthetic output, never a real merchant request.
require_once __DIR__.'/Database.php';
$database=TestDatabase::fromEnvironment();$previousApp=$app;
$app=new think\App(dirname(__DIR__,2));$app->setEnvName('test-disabled');$app->setRuntimePath(dirname(__DIR__,2).'/runtime/tests/notification-controls/');$app->initialize();
$app->config->set(['environment'=>'testing'],'mpay');
$clock=new class extends Clock { public int $offset=0;public function now(): DateTimeImmutable{return parent::now()->modify('+'.$this->offset.' seconds');} };
$http=new class extends app\common\util\HttpClient {
    public int $calls=0;public int $status=200;public string $body='success';public array $targets=[];public ?Closure $during=null;
    public function __construct(){}
    public function get(string $url,DateTimeImmutable $deadline,?array $allowedHosts=null):Psr\Http\Message\ResponseInterface {
        $this->calls++;$this->targets[]=$url;
        if($this->during!==null){$action=$this->during;$this->during=null;$action();}
        return new GuzzleHttp\Psr7\Response($this->status,[],$this->body);
    }
};
$app->instance(Clock::class,$clock);$app->instance(app\common\util\HttpClient::class,$http);
$state=[];$password='Synthetic-Notify-Only-2086';
$actor=static function()use(&$state):AdminActionContext{return new AdminActionContext((string)$state['admin'],1,bin2hex(random_bytes(16)),'隔离通知运营测试');};
$create=static function(string $external,bool $choose=true)use($app,&$state):array{
    $raw=['pid'=>'88001','out_trade_no'=>$external,'name'=>'隔离通知订单','money'=>'12.00',
        'notify_url'=>'https://merchant.example/notify','return_url'=>'https://merchant.example/return','clientip'=>'127.0.0.1','sign_type'=>'MD5'];
    if($choose){$raw['type']='alipay';}
    $raw['sign']=$app->make(app\service\payment\epay\EpayV1Signer::class)->sign($raw,$state['key']);
    $result=$app->make(app\service\payment\epay\EpayV1ProtocolService::class)->create($raw,$choose?'mapi':'submit','127.0.0.1','pc',bin2hex(random_bytes(16)));
    parse_str(parse_url($result['payurl'],PHP_URL_QUERY),$access);return $result+['access'=>$access];
};
$task=static function()use($app,&$state):?NotifyTask{return $app->make(NotifyTaskRepository::class)->findByEvent(Notice::EVENT_PAY_SUCCESS,$state['order']['trade_no']);};
try {
    $suite->test('通知运营迁移与独立应用通道初始化',function()use($suite,$app,$database,&$state,$password):void{
        [$name]=$database->create();$pdo=$database->bindApplication($app,$name);
        $migration=new app\service\system\install\MigrationService($pdo);$migration->migrate($app->getRootPath().'database/migrations');
        $suite->same(64,$migration->inspect($app->getRootPath().'database/migrations')['completed_steps']);
        $app->make(app\service\system\install\InitializationService::class)->initialize('notify_admin',$password,'通知测试','88001');
        $state['admin']=(new app\model\admin\AdminUser())->where('username','notify_admin')->value('id');
        $application=$app->make(app\repository\payment\config\PaymentAppRepository::class)->all()[0];
        $state['key']=$app->make(app\service\payment\config\PaymentAppCredentialService::class)->secretForVersion($application['id'],$application['active_key_id']);
        $cli=app\service\system\audit\ConfigurationActionContext::cli();
        $app->make(app\service\payment\config\PaymentAppService::class)->save($application['id'],(int)$application['revision'],$application['name'],$application['pid'],true,['merchant.example'],$cli);
        $state['channel']=$app->make(app\service\payment\config\PaymentChannelEditorService::class)->create([
            'plugin_code'=>'alipay_receipt','pay_type'=>'alipay','settings'=>['sms_forwarder_secret'=>'synthetic-notify-secret','receipt_qrcode_content'=>'https://example.invalid/notify-fixture','receipt_valid_seconds'=>300],
            'channel'=>['name'=>'通知测试通道','minimum_amount'=>1,'maximum_amount'=>100000,'daily_limit_amount'=>0,'daily_limit_count'=>0,'available_from'=>null,'available_to'=>null], 'enabled'=>true],$cli);
        $app->request->withServer(['HTTP_HOST'=>'pay.example','HTTPS'=>'on','SERVER_PORT'=>'443']);
        $suite->true($state['admin']!==null);
    });
    $suite->test('超时支付单可人工确认并持久暂不通知且重复确认不改变选择',function()use($suite,$app,$create,&$state,$actor,$clock,$http,$password,$task):void{
        $state['order']=$create('notify-held');$pay=$app->make(PayOrderRepository::class)->find($state['order']['trade_no']);
        $state['paid_at']=(new DateTimeImmutable($pay['created_at'],new DateTimeZone('Asia/Shanghai')))->modify('+1 second');
        $state['evidence']=new ManualReceiptEvidence(1200,$state['paid_at'],'synthetic-notify-trade','合成账单核对');
        $clock->offset=400;$lifecycle=$app->make(PayOrderLifecycleService::class);
        $suite->same(true,$lifecycle->expire($pay['biz_no'],$clock->now()));
        $actions=$app->make(PayOrderAdminActionService::class);
        $confirmed=$actions->confirm($pay['pay_no'],$actor(),$state['evidence'],$password,$clock->now(),false);
        $suite->same(2,$confirmed['status']);$suite->same(Notice::STATUS_HELD,$task()['status']);
        $suite->same(null,$task()['next_retry_at']);$suite->same(null,$task()['first_ack_at']);
        $suite->same('idle',$app->make(MerchantNotifyDispatcherService::class)->dispatchOne(bin2hex(random_bytes(16))));$suite->same(0,$http->calls);
        $actions->confirm($pay['pay_no'],$actor(),$state['evidence'],$password,$clock->now(),true);
        $suite->same(Notice::STATUS_HELD,$task()['status']);$suite->same(1,(new NotifyTask())->count());
        $access=$state['order']['access'];$cashier=$app->make(app\service\payment\cashier\CashierService::class)->view($access['biz_no'],$access['token'],$clock->now());
        $suite->same('held',$cashier['notification_status']);$suite->same(2,$cashier['status']);
        $state['frozen']=$task()['notify_data'];$state['created']=$task()['created_at'];
    });
    $suite->test('人工暂缓后补到真实流水仍不自动通知且流水幂等',function()use($suite,$app,&$state,$clock,$task):void{
        $pay=$app->make(PayOrderRepository::class)->find($state['order']['trade_no']);
        $config=(new app\model\payment\PaymentPluginConf())->find($state['channel']['configuration_id']);
        $event=new app\service\payment\receipt\ReceiptEvent($pay['receipt_scope_key'],$config['account_key'],1,'alipay',
            'synthetic-notify-event',1200,$state['paid_at'],'synthetic-notify-trade',null,str_repeat('a',64),'alipay_receipt','1.0.0',$config['id'],$pay['pay_no']);
        // Internal normalized event tests lifecycle idempotency, not external device signature verification.
        $lifecycle=$app->make(PayOrderLifecycleService::class);
        $suite->same(true,$lifecycle->confirmReceipt($event,$clock->now(),bin2hex(random_bytes(16)))['matched']);
        $lifecycle->confirmReceipt($event,$clock->now(),bin2hex(random_bytes(16)));
        $suite->same(Notice::STATUS_HELD,$task()['status']);$suite->same(1,(new NotifyTask())->count());
        $suite->same(1,(new app\model\payment\ReceiptFlow())->count());
        $date=$clock->now()->format('Y-m-d');$range=$app->make(app\http\admin\validation\OperationsQueryValidator::class)->day(['date'=>$date]);
        $stats=$app->make(app\service\ops\stat\PaymentStatisticsService::class)->day($range);
        $suite->same(0,$stats['unknown_state_records']);$suite->same(1,$stats['notification_counts'][Notice::STATUS_HELD]);
    });
    $suite->test('隔日解除暂缓开始正常重试且立即发送复用原任务',function()use($suite,$app,&$state,$clock,$http,$actor,$task):void{
        $clock->offset=200000;$actions=$app->make(PayOrderAdminActionService::class);$payNo=$state['order']['trade_no'];
        $actions->retryNotification($payNo,0,$actor(),$clock->now());$released=$task()['released_at'];
        $suite->true($released>$state['created']);$suite->same(null,$task()['manual_attempt_no']);
        $actions->retryNotification($payNo,0,$actor(),$clock->now());$suite->same($released,$task()['released_at']);
        $http->body='fail';$dispatcher=$app->make(MerchantNotifyDispatcherService::class);
        $suite->same('retry',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));
        $suite->same(1,$http->calls);$suite->same(1,$task()['retry_count']);$suite->same($state['created'],$task()['created_at']);
        $actions->retryNotification($payNo,1,$actor(),$clock->now());$http->body='success';
        $suite->same('acknowledged',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));
        $suite->same(2,$http->calls);$suite->true($task()['first_ack_at']!==null);$state['first_ack']=$task()['first_ack_at'];
        $suite->same($state['frozen'],$task()['notify_data']);$suite->same($http->targets[0],$http->targets[1]);
    });
    $suite->test('已确认商户可单次补发且并发派发与重复点击不生成第二次尝试',function()use($suite,$app,&$state,$clock,$http,$actor,$task):void{
        $actions=$app->make(PayOrderAdminActionService::class);$dispatcher=$app->make(MerchantNotifyDispatcherService::class);$payNo=$state['order']['trade_no'];
        $actions->retryNotification($payNo,2,$actor(),$clock->now());$actions->retryNotification($payNo,2,$actor(),$clock->now());
        $suite->same(3,$task()['manual_attempt_no']);
        $http->during=function()use($suite,$dispatcher,$actions,$payNo,$actor,$clock):void{
            $suite->same('idle',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));
            $suite->throws(fn()=>$actions->retryNotification($payNo,3,$actor(),$clock->now()),BusinessException::class,40900);
        };
        $http->status=500;$http->body='synthetic merchant failure';
        $suite->same('exhausted',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));
        $suite->same(3,$task()['retry_count']);$suite->same($state['first_ack'],$task()['first_ack_at']);
        $suite->same(null,$task()['next_retry_at']);$suite->same('idle',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));
        $suite->same(3,$http->calls);$suite->same(2,$app->make(PayOrderRepository::class)->find($payNo)['status']);
        $view=$app->make(app\service\payment\notify\NotifyQueryService::class)->detail($task()['notify_no']);
        $suite->same('最近补发未确认',$view['status_label']);$suite->same($state['first_ack'],$view['first_ack_at']);
        $attempts=$app->make(app\service\payment\notify\NotifyQueryService::class)->attempts($task()['notify_no'],1,20);
        $suite->same(3,$attempts['total']);$suite->same('synthetic merchant failure',$attempts['items'][0]['response_body']);
        $suite->throws(fn()=>$actions->retryNotification($payNo,2,$actor(),$clock->now()),BusinessException::class,40900);
        $filters=$app->make(app\http\admin\validation\PayOrderValidator::class)->filters(['notification_status'=>'acknowledged','biz_no'=>$state['order']['access']['biz_no']])['filters'];
        $suite->same(1,$app->make(app\repository\payment\trade\BizOrderRepository::class)->adminCount($filters));
    });
    $suite->test('补发再次成功不覆盖首次确认且过期租约不接受迟到应答',function()use($suite,$app,&$state,$clock,$http,$actor,$task):void{
        $actions=$app->make(PayOrderAdminActionService::class);$dispatcher=$app->make(MerchantNotifyDispatcherService::class);$payNo=$state['order']['trade_no'];
        $clock->offset+=10;$actions->retryNotification($payNo,3,$actor(),$clock->now());$http->status=200;$http->body='success';
        $suite->same('acknowledged',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));$suite->same($state['first_ack'],$task()['first_ack_at']);
        $actions->retryNotification($payNo,4,$actor(),$clock->now());$http->during=function()use($clock):void{$clock->offset+=60;};
        $suite->same('lease_lost',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));
        $suite->same('exhausted',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));
        $suite->same('idle',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));$suite->same(5,$http->calls);
        $suite->same($state['first_ack'],$task()['first_ack_at']);$suite->same(1,(new NotifyTask())->count());
    });
    $suite->test('未选通道超时订单显示不能补确认原因且不能通知',function()use($suite,$app,$create,&$state,$clock):void{
        $clock->offset=0;$created=$create('notify-no-payment',false);$suite->same('',$created['trade_no']);
        $clock->offset=2000;$app->make(PayOrderLifecycleService::class)->expire($created['access']['biz_no'],$clock->now());
        $detail=$app->make(app\service\payment\order\PayOrderQueryService::class)->detail($created['access']['biz_no']);
        $suite->same(false,$detail['actions']['confirm']);$suite->same(false,$detail['actions']['retry_notification']);
        $suite->same('尚未发起支付，无法补确认',$detail['actions']['confirm_unavailable_reason']);
    });
} finally {$database->cleanup();think\Container::setInstance($previousApp);$app=$previousApp;}
