<?php
declare(strict_types=1);

use app\common\constant\RouteConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\repository\payment\config\PaymentAppRepository;
use app\repository\payment\config\PaymentCatalogRepository;
use app\repository\payment\config\PaymentPollGroupRepository;
use app\service\system\audit\ConfigurationActionContext;
use app\service\payment\config\PaymentAppService;
use app\service\payment\config\PaymentCatalogService;
use app\service\payment\config\PaymentChannelEditorService;
use app\service\payment\config\PaymentConfigurationQueryService;
use app\service\payment\routing\PaymentPollGroupService;
use app\service\system\install\InitializationService;
use app\service\system\install\MigrationService;

// 独立随机库验证首次上手，不借用开发库的演示应用或既有绑定。
require_once __DIR__ . '/Database.php';
$database = TestDatabase::fromEnvironment();
$defaultState = [];
$actor = ConfigurationActionContext::cli();
try {
    $suite->test('空库安装自动创建默认应用绑定和每种方式的默认组', function () use ($suite, $app, $database, &$defaultState): void {
        [$databaseName] = $database->create(); $pdo = $database->bindApplication($app, $databaseName);
        (new MigrationService($pdo))->migrate($app->getRootPath().'database/migrations');
        $initialized = $app->make(InitializationService::class)->initialize('default_fixture', 'Synthetic-Default-2086', '默认应用', '81001');
        $defaultState['app'] = $initialized['app_id'];
        $groups = $app->make(PaymentPollGroupRepository::class);
        $suite->same(3, count($groups->all())); $suite->same(3, count($groups->bindings($defaultState['app'])));
        foreach ($app->make(PaymentCatalogRepository::class)->all() as $type) {
            $binding = $groups->binding($defaultState['app'], $type['id']);
            $suite->same($type['default_poll_group_id'], $binding['poll_group_id']);
            $suite->same(RouteConstant::ORDER, $groups->find($binding['poll_group_id'])['route_mode']);
            $suite->same([], $groups->members($binding['poll_group_id']));
        }
        $suite->throws(fn () => $app->make(PaymentPollGroupService::class)->preview($defaultState['app'], 'wxpay', 100), BusinessException::class, 50300);
        $before = $pdo->query('SELECT * FROM ma_payment_poll_group_bind ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $app->db->transaction(fn () => $app->make(\app\service\payment\routing\DefaultRoutingService::class)->applyToApp($defaultState['app']));
        $suite->same($before, $pdo->query('SELECT * FROM ma_payment_poll_group_bind ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $suite->same('initialized', $app->make(InitializationService::class)->status()['phase']);
    });

    $suite->test('保存并启用通道原子加入默认组且新应用直接选路', function () use ($suite, $app, &$defaultState, $actor): void {
        $editor = $app->make(PaymentChannelEditorService::class);
        $rules = ['name'=>'默认收款甲','minimum_amount'=>1,'maximum_amount'=>1000,'daily_limit_amount'=>0,'daily_limit_count'=>0,'available_from'=>null,'available_to'=>null];
        $input = ['plugin_code'=>'wxpay_receipt','pay_type'=>'wxpay','settings'=>['sms_forwarder_secret'=>'synthetic-default-secret','receipt_qrcode_content'=>'wxp://default-fixture'],
            'channel'=>$rules,'enabled'=>true,'participates_default_route'=>true];
        $defaultState['first'] = $editor->create($input, $actor);
        $input['channel']['name']='默认收款乙';$defaultState['second']=$editor->create($input,$actor);
        $suite->same(true, $defaultState['first']['enabled']);
        $repo=$app->make(PaymentPollGroupRepository::class);$type=$app->make(PaymentCatalogRepository::class)->findCode('wxpay');
        $defaultState['group']=$type['default_poll_group_id'];$members=$repo->members($defaultState['group']);
        $suite->same([$defaultState['first']['id'],$defaultState['second']['id']],array_column(array_map(fn($row)=>$row->toArray(),$members),'channel_id'));
        $suite->same([1,1],array_column(array_map(fn($row)=>$row->toArray(),$members),'weight'));
        $id=$app->make(PaymentAppService::class)->create('自动接入应用','81002',['merchant.example'],$actor)['id'];$defaultState['new_app']=$id;
        $suite->same(3,count($repo->bindings($id)));
        $suite->same($defaultState['first']['id'],$app->make(PaymentPollGroupService::class)->preview($id,'wxpay',500)['channel_id']);
        // 启用阶段失败必须回滚创建，不能残留半条通道或成员。
        $input['settings']['receipt_qrcode_content']='';
        $suite->throws(fn()=>$editor->create($input,$actor),BusinessException::class);
        $suite->same(2,count($repo->members($defaultState['group'])));
    });

    $suite->test('自定义路由不被新增通道覆盖且恢复默认检查版本', function () use ($suite,$app,&$defaultState,$actor): void {
        $apps=$app->make(PaymentAppService::class);$repo=$app->make(PaymentAppRepository::class);$groups=$app->make(PaymentPollGroupRepository::class);
        $id=$defaultState['new_app'];$old=$repo->find($id);
        $apps->routing($id,$old['revision'],false,$actor);
        $suite->same(0,$repo->find($id)['use_default_route']);
        $custom=$app->make(PaymentPollGroupService::class)->create('自定义空组','wxpay',RouteConstant::ORDER,'',[],$actor);
        $type=$app->make(PaymentCatalogRepository::class)->findCode('wxpay');$bind=$groups->binding($id,$type['id']);
        $app->make(PaymentPollGroupService::class)->bind($id,'wxpay',$custom['id'],$bind['revision'],true,$actor);
        $suite->throws(fn()=>$app->make(PaymentPollGroupService::class)->preview($id,'wxpay',500),BusinessException::class,50300);
        $current=$repo->find($id);
        $suite->throws(fn()=>$apps->routing($id,$old['revision'],true,$actor),BusinessException::class,40900);
        $key=$current['active_key_id'];$apps->routing($id,$current['revision'],true,$actor);
        $suite->same($key,$repo->find($id)['active_key_id']);$suite->same(1,$repo->find($id)['use_default_route']);
        $suite->same($defaultState['group'],$groups->binding($id,$type['id'])['poll_group_id']);
        $suite->same($defaultState['first']['id'],$app->make(PaymentPollGroupService::class)->preview($id,'wxpay',500)['channel_id']);
    });

    $suite->test('默认组保护成员集合并保留参与选择与管理员排序', function () use ($suite,$app,&$defaultState,$actor): void {
        $service=$app->make(PaymentPollGroupService::class);$id=$defaultState['group'];
        $group=array_values(array_filter($service->listing()['items'],fn($row)=>$row['id']===$id))[0];
        $suite->same(true,$group['is_default']);
        $suite->throws(fn()=>$service->save($id,$group['revision'],$group['name'],RouteConstant::ORDER,false,'',$group['members'],$actor),BusinessException::class,40900);
        $suite->throws(fn()=>$service->save($id,$group['revision'],$group['name'],RouteConstant::ORDER,true,'',[],$actor),BusinessException::class,40900);
        $members=$group['members'];$members[0]['enabled']=false;$members[1]['weight']=7;
        $service->save($id,$group['revision'],'默认微信收款',RouteConstant::ORDER,true,'',$members,$actor);
        $detail=$app->make(PaymentConfigurationQueryService::class)->channel($defaultState['first']['id']);
        $suite->same(false,$detail['participates_default_route']);
        $suite->same($defaultState['second']['id'],$service->preview($defaultState['new_app'],'wxpay',500)['channel_id']);
        $editor=$app->make(PaymentChannelEditorService::class);$rules=$detail['channel'];unset($rules['scope_key'],$rules['pay_type']);
        $editor->save(['id'=>$detail['id'],'expected_revision'=>$detail['revision_id'],'expected_configuration_version'=>$detail['configuration_version'],
            'plugin_code'=>'wxpay_receipt','pay_type'=>'wxpay','settings'=>[],'channel'=>$rules],$actor);
        $suite->same(false,$app->make(PaymentConfigurationQueryService::class)->channel($detail['id'])['participates_default_route']);
        $members=$app->make(PaymentPollGroupRepository::class)->members($id);$suite->same(7,$members[1]['weight']);
    });

    $suite->test('新增支付方式仅扩展默认应用并可删除空的默认组', function () use ($suite,$app,&$defaultState,$actor): void {
        $apps=$app->make(PaymentAppService::class);$repo=$app->make(PaymentAppRepository::class);$old=$repo->find($defaultState['app']);
        $apps->routing($old['id'],$old['revision'],false,$actor);
        $catalog=$app->make(PaymentCatalogService::class);
        $created=$catalog->saveType(['id'=>null,'revision'=>0,'code'=>'fixture_default','name'=>'新方式','sort_order'=>9,'enabled'=>true,'icon_asset_id'=>null,'logo_asset_id'=>null],$actor);
        $type=$app->make(PaymentCatalogRepository::class)->find($created['id']);$groups=$app->make(PaymentPollGroupRepository::class);
        $suite->same(null,$groups->binding($old['id'],$type['id']));
        $suite->same($type['default_poll_group_id'],$groups->binding($defaultState['new_app'],$type['id'])['poll_group_id']);
        $catalog->deleteType($type['id'],$type['revision'],$actor);
        $suite->same(null,$groups->find($type['default_poll_group_id']));
        $suite->same(null,$groups->binding($defaultState['new_app'],$type['id']));
    });

    $suite->test('通道切换支付方式自动移组且默认引用不阻止删除', function () use ($suite,$app,&$defaultState,$actor): void {
        $editor=$app->make(PaymentChannelEditorService::class);$query=$app->make(PaymentConfigurationQueryService::class);
        $detail=$query->channel($defaultState['first']['id']);$rules=$detail['channel'];unset($rules['scope_key'],$rules['pay_type']);
        $saved=$editor->save(['id'=>$detail['id'],'expected_revision'=>$detail['revision_id'],'expected_configuration_version'=>$detail['configuration_version'],
            'plugin_code'=>'alipay_receipt','pay_type'=>'alipay','settings'=>['sms_forwarder_secret'=>'synthetic-alipay-secret','receipt_qrcode_content'=>'https://example.invalid/default'],
            'channel'=>$rules,'participates_default_route'=>true],$actor);
        $groups=$app->make(PaymentPollGroupRepository::class);$types=$app->make(PaymentCatalogRepository::class);
        $suite->same(1,count($groups->members($defaultState['group'])));
        $suite->same($detail['id'],$groups->members($types->findCode('alipay')['default_poll_group_id'])[0]['channel_id']);
        $editor->delete(['id'=>$detail['id'],'expected_revision'=>$saved['revision_id'],'expected_configuration_version'=>$saved['configuration_version']],$actor);
        $suite->same([], $groups->members($types->findCode('alipay')['default_poll_group_id']));
    });

    $suite->test('收银台过滤空组超限方式且默认路由预览不创建支付单', function () use ($suite,$app,&$defaultState,$actor): void {
        $repo=$app->make(PaymentAppRepository::class);$application=$repo->find($defaultState['new_app']);
        $key=$app->make(\app\service\payment\config\PaymentAppCredentialService::class)->secretForVersion($application['id'],$application['active_key_id']);
        $app->request->withServer(['HTTP_HOST'=>'pay.example','HTTPS'=>'on','SERVER_PORT'=>'443']);
        $protocol=$app->make(\app\service\payment\epay\EpayV1ProtocolService::class);
        $groups=$app->make(PaymentPollGroupRepository::class);$type=$app->make(PaymentCatalogRepository::class)->findCode('wxpay');
        $before=$groups->binding($application['id'],$type['id'])->toArray();
        foreach (['50.00'=>[], '5.00'=>['wxpay']] as $money=>$expected) {
            $raw=['pid'=>$application['pid'],'out_trade_no'=>'default-page-'.$money,'name'=>'默认路由合成订单','money'=>$money,'notify_url'=>'https://merchant.example/notify','return_url'=>'https://merchant.example/return','sign_type'=>'MD5'];
            $raw['sign']=$app->make(\app\service\payment\epay\EpayV1Signer::class)->sign($raw,$key);
            $result=$protocol->create($raw,'submit','127.0.0.1','pc',bin2hex(random_bytes(16)));parse_str(parse_url($result['payurl'],PHP_URL_QUERY),$query);
            $view=$app->make(\app\service\payment\cashier\CashierService::class)->view($query['biz_no'],$query['token'],$app->make(Clock::class)->now());
            $suite->same($expected,array_column($view['payment_methods'],'code'));$suite->same(null,$view['pay_no']);
            if($expected!==[])$defaultState['cashier']=$query;
        }
        $suite->same($before,$groups->binding($application['id'],$type['id'])->toArray());
        $query=$defaultState['cashier'];
        $view=$app->make(\app\service\payment\cashier\CashierService::class)->selectType($query['biz_no'],$query['token'],'wxpay',bin2hex(random_bytes(16)));
        $suite->same(true,$view['can_pay']);
        $suite->same($defaultState['second']['id'],(new \app\model\payment\PayOrder())->where('pay_no',$view['pay_no'])->find()['channel_id']);
    });
} finally {
    $app->db->connect('mysql')->close(); $database->cleanup();
}
