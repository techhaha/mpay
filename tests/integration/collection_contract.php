<?php
declare(strict_types=1);

use app\service\system\audit\ConfigurationActionContext;
use app\service\payment\config\PaymentCatalogService;
use app\service\payment\config\PaymentChannelEditorService;
use app\service\payment\config\PaymentConfigurationQueryService;
use app\service\payment\collection\ReceiptWatcherService;

require_once __DIR__ . '/Database.php';
require_once dirname(__DIR__) . '/fixtures/AuditPayment.php';
require_once dirname(__DIR__) . '/fixtures/AuditCollectorPayment.php';
$database = TestDatabase::fromEnvironment(); $previousApp = $app;
$app = new think\App(dirname(__DIR__, 2));
$app->setEnvName('test-disabled'); $app->setRuntimePath(dirname(__DIR__, 2) . '/runtime/tests/collection-contract/');
$app->initialize(); $app->config->set(['environment'=>'testing'], 'mpay');
$app->config->set(['test_plugins'=>['audit_payment'=>AuditPayment::class]], 'payment');
$actor = ConfigurationActionContext::cli(); $created = null; $settings = null;
$rules = ['name'=>'接口采集测试', 'minimum_amount'=>1, 'maximum_amount'=>100000, 'daily_limit_amount'=>0,
    'daily_limit_count'=>0, 'available_from'=>null, 'available_to'=>null];
try {
    $suite->test('未实现采集接口的已启用插件不会被监听调度', function () use ($suite,$app,$database,$actor,$rules,&$created,&$settings): void {
        [$name] = $database->create(); $pdo = $database->bindApplication($app,$name);
        (new app\service\system\install\MigrationService($pdo))->migrate($app->getRootPath().'database/migrations');
        $app->make(app\service\system\install\InitializationService::class)->initialize('fixture_admin','Synthetic-Only-2086','采集合同','99002');
        $catalog = $app->make(PaymentCatalogService::class); $catalog->refresh($actor);
        $entry = $app->make(app\repository\payment\config\PaymentPluginRepository::class)->find('audit_payment');
        $catalog->pluginEnabled(['code'=>'audit_payment','revision'=>(int)$entry['revision'],'enabled'=>true],$actor);
        $settings = $app->make(app\common\validation\PluginConfigValidator::class)->validate(
            $app->make(app\service\payment\plugin\PaymentPluginFactoryService::class)->definition('audit_payment')->configSchema,
            ['sms_forwarder_secret'=>'synthetic-secret', 'receipt_qrcode_content'=>'wxp://synthetic'], true);
        $created = $app->make(PaymentChannelEditorService::class)->create(['plugin_code'=>'audit_payment','pay_type'=>'wxpay',
            'settings'=>$settings,'channel'=>$rules,'enabled'=>true],$actor);
        $suite->same(0,(new app\model\payment\ReceiptRuntimeState())->count());
        $suite->same('idle',$app->make(ReceiptWatcherService::class)->collectOne(bin2hex(random_bytes(16))));
    });
    $suite->test('升级采集接口后保存相同配置会登记任务并更新快照', function () use ($suite,$app,$actor,&$created,&$settings): void {
        $app->config->set(['test_plugins'=>['audit_payment'=>AuditCollectorPayment::class]], 'payment');
        $app->make(PaymentCatalogService::class)->refresh($actor);
        $suite->same(0,(new app\model\payment\ReceiptRuntimeState())->count());
        $query = $app->make(PaymentConfigurationQueryService::class); $editor = $app->make(PaymentChannelEditorService::class);
        $before = $query->channel($created['id']);
        $saved = $editor->configure(['id'=>$created['id'],'expected_revision'=>$before['revision_id'],'expected_configuration_version'=>1,
            'settings'=>$settings,'retain_secrets'=>[]],$actor);
        $suite->same(2,$saved['config_version']); $suite->same(1,(new app\model\payment\ReceiptRuntimeState())->count());
        $after = $query->channel($created['id']); $suite->same(false,$after['enabled']);
        $suite->true($before['revision_id'] !== $after['revision_id']);
        $suite->same('idle',$app->make(ReceiptWatcherService::class)->collectOne(bin2hex(random_bytes(16))));
        $editor->setEnabled(['id'=>$created['id'],'expected_revision'=>$after['revision_id'],'expected_configuration_version'=>2,'enabled'=>true],$actor);
    });
    $suite->test('任一页失败不匹配部分流水且完整分页只投递一次', function () use ($suite,$app): void {
        $watch = $app->make(ReceiptWatcherService::class);
        AuditCollectorPayment::$failSecondPage = true;
        $suite->same('retry',$watch->collectOne(bin2hex(random_bytes(16))));
        $suite->same([],AuditCollectorPayment::$batches);
        $state = (new app\model\payment\ReceiptRuntimeState())->where('id','>',0)->find();
        // 首次整窗失败仍停留在初始覆盖起点，没有把已取得的第一页记成完成。
        $suite->same($state['cursor_json']['coverage_from'],$state['cursor_json']['completed_through']);
        $before = $state['cursor_json']['completed_through'];
        AuditCollectorPayment::$failSecondPage = false;
        (new app\model\payment\ReceiptRuntimeState())->where('id',$state['id'])->update(['next_run_at'=>date('Y-m-d H:i:s',time()-1)]);
        $suite->same('completed',$watch->collectOne(bin2hex(random_bytes(16))));
        $suite->same([3],AuditCollectorPayment::$batches); $suite->same(4,AuditCollectorPayment::$pages);
        $suite->true((new app\model\payment\ReceiptRuntimeState())->where('id','>',0)->find()['cursor_json']['completed_through'] > $before);
        $suite->same(0,(new app\model\payment\ReceiptFlow())->count());
    });
    $suite->test('未改变身份参数及版本的保存不产生新配置版本', function () use ($suite,$app,$actor,&$created,&$settings): void {
        $query = $app->make(PaymentConfigurationQueryService::class); $before = $query->channel($created['id']);
        $saved = $app->make(PaymentChannelEditorService::class)->configure(['id'=>$created['id'],'expected_revision'=>$before['revision_id'],
            'expected_configuration_version'=>2,'settings'=>$settings,'retain_secrets'=>[]],$actor);
        $suite->same(2,$saved['config_version']);
        $suite->same($before['revision_id'],$query->channel($created['id'])['revision_id']);
        $suite->same(true,$query->channel($created['id'])['enabled']);
    });
} finally { $database->cleanup(); think\Container::setInstance($previousApp); $app = $previousApp; }
