<?php
declare(strict_types=1);

use app\common\constant\NotifyConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\model\payment\NotifyTask;
use app\repository\payment\config\PaymentAppRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\system\audit\ConfigurationActionContext;
use app\service\payment\config\PaymentAppCredentialService;
use app\service\payment\config\PaymentAppService;
use app\service\payment\config\PaymentChannelEditorService;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\routing\PaymentPollGroupService;
use app\service\payment\epay\EpayV1Signer;
use app\service\payment\epay\EpayV1ProtocolService;
use app\service\payment\receipt\ChannelNotifyContext;
use app\service\payment\receipt\PayOrderCallbackService;
use app\service\payment\receipt\ReceiptRecordValidator;
use app\service\payment\receipt\ReceiptScopeService;
use app\service\system\install\InitializationService;
use app\service\system\install\MigrationService;

// 只有 --integration 且显式 MPAY_TEST_CONFIG 才能到此；随机库由本对象创建并清理，不回退业务 .env。
require_once __DIR__ . '/Database.php';
$database = TestDatabase::fromEnvironment();
$state = [];
$actor = ConfigurationActionContext::cli('重构隔离合成场景');
$password = 'Synthetic-Admin-Only-2086';
try {
    $suite->test('建表续跑保留步骤并拒绝陌生表、丢表和摘要变化', function () use ($suite, $app, $database): void {
        $directory = $app->getRootPath() . 'database/migrations';
        [, $foreign] = $database->create();
        $foreign->exec('CREATE TABLE unrelated_data (id INT PRIMARY KEY) ENGINE=InnoDB');
        $suite->throws(fn () => (new MigrationService($foreign))->migrate($directory), RuntimeException::class);
        $suite->same(['unrelated_data'], $foreign->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));

        $file = glob($directory . '/*.php')[0];
        $definitions = require $file;
        $first = array_key_first($definitions);
        $version = pathinfo($file, PATHINFO_FILENAME);
        $checksum = hash('sha256', $definitions[$first]);
        // 分别模拟写 pending 后尚未执行 DDL、以及 DDL 已成功但未写 complete 的中断现场。
        foreach ([false, true] as $tableCreated) {
            [, $pdo] = $database->create();
            $migrations = new MigrationService($pdo);
            $suite->same(false, $migrations->inspect($directory)['installed']);
            $pdo->exec("CREATE TABLE _mpay_migration_step (
                version VARCHAR(64) NOT NULL, step VARCHAR(64) NOT NULL, checksum CHAR(64) NOT NULL,
                state VARCHAR(16) NOT NULL, completed_at DATETIME NULL, PRIMARY KEY(version,step)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
            // 未建表时允许修正原失败 SQL；已建表时摘要必须仍与原 SQL 相同。
            $recordedChecksum = $tableCreated ? $checksum : hash('sha256', $definitions[$first] . "\n");
            $pdo->prepare("INSERT INTO _mpay_migration_step (version,step,checksum,state) VALUES (?,?,?,'pending')")
                ->execute([$version, $first, $recordedChecksum]);
            if ($tableCreated) { $pdo->exec($definitions[$first]); }
            $suite->same(0, $migrations->inspect($directory)['completed_steps']);
            $suite->same('pending', $pdo->query('SELECT state FROM _mpay_migration_step')->fetchColumn());
            $suite->same($recordedChecksum, $pdo->query('SELECT checksum FROM _mpay_migration_step')->fetchColumn());
            if ($tableCreated) {
                $pdo->exec("UPDATE _mpay_migration_step SET checksum='" . str_repeat('0', 64) . "'");
                $suite->throws(fn () => $migrations->inspect($directory), RuntimeException::class);
                $pdo->prepare('UPDATE _mpay_migration_step SET checksum=?')->execute([$checksum]);
            }
            $migrations->migrate($directory);
            $statement = $pdo->prepare('SELECT checksum FROM _mpay_migration_step WHERE version=? AND step=?');
            $statement->execute([$version, $first]);
            $suite->same($checksum, $statement->fetchColumn());
            $suite->same(64, $migrations->inspect($directory)['completed_steps']);
            $original = $pdo->query('SELECT * FROM _mpay_migration_step ORDER BY version,step')->fetchAll(PDO::FETCH_ASSOC);
            $migrations->migrate($directory);
            $suite->same($original, $pdo->query('SELECT * FROM _mpay_migration_step ORDER BY version,step')->fetchAll(PDO::FETCH_ASSOC));
            $suite->same(3, (int) $pdo->query('SELECT COUNT(*) FROM ma_payment_type')->fetchColumn());

            $pdo->prepare('UPDATE _mpay_migration_step SET checksum=? WHERE version=? AND step=?')
                ->execute([str_repeat('0', 64), $version, $first]);
            $suite->throws(fn () => $migrations->migrate($directory), RuntimeException::class);
            $pdo->prepare('UPDATE _mpay_migration_step SET checksum=? WHERE version=? AND step=?')
                ->execute([$checksum, $version, $first]);
            // 最后一张表没有后续依赖；只在本测试创建的随机库中模拟丢表，迁移不得将其悄悄重建。
            $last = array_key_last($definitions);
            $pdo->exec('DROP TABLE ' . $last);
            $suite->throws(fn () => $migrations->migrate($directory), RuntimeException::class);
            $suite->true(!in_array($last, $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), true));
        }
    });

    $suite->test('新装基线及两个应用共用通道和轮询组', function () use ($suite, $app, $database, &$state, $actor, $password): void {
        [$name] = $database->create();
        $pdo = $database->bindApplication($app, $name);
        $versions = (new MigrationService($pdo))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $suite->same(['202610080100_v1_install','202610080200_admin_ui','202610090100_plugin_catalog','202610090200_refund_order','202610090300_default_routing','202610090400_collector_session','202610100100_notification_controls','202610100200_schema_comments'], $versions);
        $initialized = $app->make(InitializationService::class)->initialize('fixture_admin', $password, '应用甲', '1001');
        $apps = $app->make(PaymentAppService::class); $repository = $app->make(PaymentAppRepository::class);
        $first = $repository->find($initialized['app_id']);
        $apps->save($first['id'], (int) $first['revision'], '应用甲', '1001', true, ['merchant.example'], $actor);
        $state['a'] = $repository->find($first['id']);
        $state['b'] = $repository->find($apps->create('应用乙', '1002', ['merchant.example'], $actor)['id']);
        $state['admin_id'] = $initialized['admin_id'];
        $credentials = $app->make(PaymentAppCredentialService::class);
        foreach (['a', 'b'] as $key) { $state[$key . '_key'] = $credentials->secretForVersion($state[$key]['id'], $state[$key]['active_key_id']); }
        $state['settings'] = $app->make(\app\common\validation\PluginConfigValidator::class)->validate(
            $app->make(PaymentPluginFactoryService::class)->definition('wxpay_receipt')->configSchema,
            ['sms_forwarder_secret' => 'synthetic-push-secret', 'sms_forwarder_time_tolerance' => 300,
                'receipt_qrcode_content' => 'wxp://synthetic'], true);
        $state['rules'] = ['name'=>'合成通道', 'minimum_amount'=>1, 'maximum_amount'=>999999999,
            'daily_limit_amount'=>0, 'daily_limit_count'=>0, 'available_from'=>null, 'available_to'=>null];
        foreach (['one', 'two'] as $number) {
            $created = $app->make(PaymentChannelEditorService::class)->create(['plugin_code'=>'wxpay_receipt',
                'pay_type'=>'wxpay', 'settings'=>$state['settings'], 'channel'=>array_replace($state['rules'], ['name'=>'通道 ' . $number]),
                'enabled'=>true], $actor);
            $state[$number] = $created;
        }
        $state['configuration'] = $state['one']['configuration_id'];
        $state['scope'] = $app->make(\app\repository\payment\config\PaymentPluginConfScopeRepository::class)->keys($state['configuration'], 1)[0];
        $suite->true($state['one']['configuration_id'] !== $state['two']['configuration_id']);
        $members = [
            ['channel_id' => $state['one']['id'], 'sort_no' => 0, 'weight' => 1, 'is_default' => false, 'enabled' => true],
            ['channel_id' => $state['two']['id'], 'sort_no' => 1, 'weight' => 2, 'is_default' => true, 'enabled' => true],
        ];
        $state['members'] = $members;
        $group = $app->make(PaymentPollGroupService::class)->create('顺序组', 'wxpay', \app\common\constant\RouteConstant::ORDER, '', $members, $actor);
        $state['group'] = $group['id'];
        foreach (['a', 'b'] as $key) { $app->make(PaymentPollGroupService::class)->bind($state[$key]['id'], 'wxpay', $group['id'], 1, true, $actor); }
        $app->request->withServer(['HTTP_HOST' => 'pay.example', 'HTTPS' => 'on', 'SERVER_PORT' => '443']);
        $suite->true($state['a']['id'] !== $state['b']['id']);
    });

    $create = static function (string $appName, string $external) use ($app, &$state): array {
        $raw = ['pid' => $state[$appName]['pid'], 'type' => 'wxpay', 'out_trade_no' => $external, 'name' => '合成订单',
            'money' => '10.00', 'notify_url' => 'https://merchant.example/notify', 'return_url' => 'https://merchant.example/return',
            'clientip' => '127.0.0.1', 'sign_type' => 'MD5'];
        $raw['sign'] = $app->make(EpayV1Signer::class)->sign($raw, $state[$appName . '_key']);
        return $app->make(EpayV1ProtocolService::class)->create($raw, 'mapi', '127.0.0.1', 'pc', bin2hex(random_bytes(16)));
    };

    $suite->test('同外部单号应用隔离且金额占用跨应用共享', function () use ($suite, $app, &$state, $create): void {
        $state['a_order'] = $create('a', 'same-external-order');
        $state['b_order'] = $create('b', 'same-external-order');
        $orders = $app->make(PayOrderRepository::class);
        $a = $orders->find($state['a_order']['trade_no']); $b = $orders->find($state['b_order']['trade_no']);
        $suite->true($a['biz_no'] !== $b['biz_no']);
        $suite->same($state['one']['id'], $a['channel_id']); $suite->same($state['one']['id'], $b['channel_id']);
        $suite->true($a->receiptAmount() !== $b->receiptAmount());
        $suite->throws(fn () => $app->make(EpayV1ProtocolService::class)->api(['pid' => '1002', 'key' => $state['b_key'],
            'act' => 'order', 'trade_no' => $a['pay_no']]), BusinessException::class, 40400);
    });

    $suite->test('预览和同单重投不推进顺序游标', function () use ($suite, $app, &$state, $create): void {
        $routes = $app->make(PaymentPollGroupService::class);
        $before = $routes->preview($state['a']['id'], 'wxpay', 1000);
        $suite->same($state['two']['id'], $before['channel_id']);
        $suite->same(false, $before['cursor_advanced']);
        $suite->same($state['a_order']['trade_no'], $create('a', 'same-external-order')['trade_no']);
        $suite->same($before['channel_id'], $routes->preview($state['a']['id'], 'wxpay', 1000)['channel_id']);
        $next = $create('a', 'next-order');
        $suite->same($state['two']['id'], $app->make(PayOrderRepository::class)->find($next['trade_no'])['channel_id']);
    });

    $suite->test('PID和KEY修改后旧单通知仍使用冻结凭证且重复流水不新增通知', function () use ($suite, $app, &$state, $actor, $password): void {
        $apps = $app->make(PaymentAppService::class); $repository = $app->make(PaymentAppRepository::class);
        $current = $repository->find($state['a']['id']);
        $admin = ConfigurationActionContext::administrator($state['admin_id'], 1, bin2hex(random_bytes(16)), '合成重置', '127.0.0.1');
        $apps->rotate($current['id'], $current['active_key_id'], null, $admin, $password);
        $current = $repository->find($current['id']);
        $apps->save($current['id'], (int) $current['revision'], $current['name'], '1003', true, [], $actor);
        $suite->throws(fn () => $app->make(EpayV1ProtocolService::class)->api(['pid' => '1001', 'key' => $state['a_key']]), BusinessException::class, 40100);
        $pay = $app->make(PayOrderRepository::class)->find($state['a_order']['trade_no']);
        $configuration = $app->make(PaymentPluginConfRepository::class)->find($state['configuration']);
        $scopes = $app->db->transaction(function () use ($app, $configuration) {
            return $app->make(ReceiptScopeService::class)->describe($app->make(ReceiptScopeService::class)->current($configuration, true));
        });
        $now = $app->make(Clock::class)->now();
        $record = $app->make(ReceiptRecordValidator::class)->validateRecord(['event_key' => 'synthetic-payment-1',
            'trade_no' => 'synthetic-payment-1', 'amount' => $pay->receiptAmount(), 'pay_type' => 'wxpay',
            'paid_at' => $now->format('Y-m-d H:i:s'), 'remark' => null]);
        $plugin = $app->make(PaymentPluginFactoryService::class)->initialize('wxpay_receipt', $state['settings'],
            ['channel_id' => $pay['channel_id'], 'receipt_scope_key' => $state['scope'], 'receipt_match_mode' => 'amount']);
        // 合成记录直接走受信任的内部结果合同；此用例不宣称验证了真实手机签名或设备格式。
        $context = new ChannelNotifyContext($pay['channel_id'], $configuration['id'], $configuration['account_key'], 1,
            'wxpay_receipt', $plugin->getVersion(), $scopes, $state['settings'], $now);
        $callbacks = $app->make(PayOrderCallbackService::class);
        $result = $plugin->notifyPayload(['record' => $record]);
        $callbacks->acceptPluginResult($result, $context, bin2hex(random_bytes(16)));
        $callbacks->acceptPluginResult($plugin->notifyPayload(['record' => $record]), $context, bin2hex(random_bytes(16)));
        $task = $app->make(NotifyTaskRepository::class)->findByEvent(NotifyConstant::EVENT_PAY_SUCCESS, $pay['pay_no']);
        $suite->same('1001', $task['notify_data']['pid']);
        $suite->true($app->make(EpayV1Signer::class)->verify($task['notify_data'], $state['a_key']));
        $suite->same(1, (new NotifyTask())->where('pay_no', $pay['pay_no'])->count());
    });

    $suite->test('默认通道失效时回退有效成员且权重组不越出成员集合', function () use ($suite, $app, &$state, $actor): void {
        $groups = $app->make(PaymentPollGroupService::class);
        $default = $groups->create('默认组', 'wxpay', \app\common\constant\RouteConstant::FIRST_AVAILABLE, '', $state['members'], $actor);
        $groups->bind($state['b']['id'], 'wxpay', $default['id'], 2, true, $actor);
        $suite->same($state['two']['id'], $groups->preview($state['b']['id'], 'wxpay', 1000)['channel_id']);
        $app->make(PaymentChannelEditorService::class)->setEnabled(['id'=>$state['two']['id'], 'expected_revision'=>$state['two']['revision_id'], 'expected_configuration_version'=>1, 'enabled'=>false], $actor);
        $suite->same($state['one']['id'], $groups->preview($state['b']['id'], 'wxpay', 1000)['channel_id']);
        $weighted = $groups->create('单有效成员权重组', 'wxpay', \app\common\constant\RouteConstant::WEIGHTED, '', $state['members'], $actor);
        $groups->bind($state['b']['id'], 'wxpay', $weighted['id'], 3, true, $actor);
        $suite->same($state['one']['id'], $groups->preview($state['b']['id'], 'wxpay', 1000)['channel_id']);
    });
    $suite->test('支付方式维护和插件停用只影响新单且刷新保留开关', function () use ($suite, $app, &$state, $actor, $create): void {
        $catalog = $app->make(\app\service\payment\config\PaymentCatalogService::class);
        $types = $app->make(\app\repository\payment\config\PaymentCatalogRepository::class);
        $plugins = $app->make(\app\repository\payment\config\PaymentPluginRepository::class);
        $created = $catalog->saveType(['id'=>null,'revision'=>0,'code'=>'fixture_pay','name'=>'合成支付方式','sort_order'=>9,
            'enabled'=>true,'icon_asset_id'=>null,'logo_asset_id'=>null], $actor);
        $row = $types->find($created['id']);
        $suite->same('fixture_pay', $row['code']);
        $catalog->deleteType($row['id'], $row['revision'], $actor);
        $suite->same(null, $types->find($row['id']));
        $wechat = $types->findCode('wxpay');
        $suite->throws(fn () => $catalog->deleteType($wechat['id'], $wechat['revision'], $actor), BusinessException::class, 40900);
        $existing = $create('b', 'catalog-existing-order');
        $suite->same(1, preg_match('/^P[0-9]{19}$/D', $existing['trade_no']));
        $plugin = $plugins->find('wxpay_receipt');
        $catalog->pluginEnabled(['code'=>'wxpay_receipt','revision'=>$plugin['revision'],'enabled'=>false], $actor);
        $catalog->refresh($actor);
        $suite->same(false, $catalog->allowsNew('wxpay_receipt'));
        $suite->same($existing['trade_no'], $create('b', 'catalog-existing-order')['trade_no']);
        $suite->throws(fn () => $create('b', 'catalog-stopped-new'), BusinessException::class, 50300);
        $suite->throws(fn () => $app->make(PaymentChannelEditorService::class)->create(['plugin_code'=>'wxpay_receipt', 'pay_type'=>'wxpay',
            'settings'=>$state['settings'], 'channel'=>$state['rules']], $actor), BusinessException::class, 40900);
        // 停用只关闭新入口，原订单的已认证结果仍走正式生命周期并生成原通知责任。
        $pay = $app->make(PayOrderRepository::class)->find($existing['trade_no']);
        $configuration = $app->make(PaymentPluginConfRepository::class)->find($state['configuration']);
        $scopes = $app->db->transaction(fn () => $app->make(ReceiptScopeService::class)
            ->describe($app->make(ReceiptScopeService::class)->current($configuration, true)));
        $now = $app->make(Clock::class)->now();
        $record = $app->make(ReceiptRecordValidator::class)->validateRecord(['event_key'=>'synthetic-disabled-plugin',
            'trade_no'=>'synthetic-disabled-plugin','amount'=>$pay->receiptAmount(),'pay_type'=>'wxpay',
            'paid_at'=>$now->format('Y-m-d H:i:s'),'remark'=>null]);
        $instance = $app->make(PaymentPluginFactoryService::class)->initialize('wxpay_receipt', $state['settings'],
            ['channel_id'=>$pay['channel_id'],'receipt_scope_key'=>$state['scope'],'receipt_match_mode'=>'amount']);
        $context = new ChannelNotifyContext($pay['channel_id'], $configuration['id'], $configuration['account_key'], 1,
            'wxpay_receipt', $instance->getVersion(), $scopes, $state['settings'], $now);
        $app->make(PayOrderCallbackService::class)->acceptPluginResult($instance->notifyPayload(['record'=>$record]), $context, bin2hex(random_bytes(16)));
        $suite->same(2, $app->make(PayOrderRepository::class)->find($pay['pay_no'])['status']);
        $task = $app->make(NotifyTaskRepository::class)->findByEvent(NotifyConstant::EVENT_PAY_SUCCESS, $pay['pay_no']);
        $suite->same(20, strlen($task['notify_no']));
        $current = $plugins->find('wxpay_receipt');
        $catalog->pluginEnabled(['code'=>'wxpay_receipt','revision'=>$current['revision'],'enabled'=>true], $actor);
        $suite->same(true, $catalog->allowsNew('wxpay_receipt'));
        $suite->same(20, strlen($create('b', 'catalog-stopped-new')['trade_no']));
        $configuration = $app->make(PaymentPluginConfRepository::class)->find($state['configuration']);
        $suite->true(is_array($configuration['config_json']));
        $suite->true(!array_key_exists('config_json', $configuration->toArray()));
        $typeInput = ['id'=>$wechat['id'],'revision'=>$wechat['revision'],'code'=>'wxpay','name'=>'微信支付',
            'sort_order'=>0,'enabled'=>false,'icon_asset_id'=>null,'logo_asset_id'=>null];
        $catalog->saveType($typeInput, $actor);
        $suite->throws(fn () => $app->make(PaymentChannelEditorService::class)->setEnabled(['id'=>$state['one']['id'], 'expected_revision'=>$state['one']['revision_id'], 'expected_configuration_version'=>1, 'enabled'=>true], $actor), BusinessException::class, 40900);
        $suite->throws(fn () => $create('b', 'disabled-payment-type'), BusinessException::class);
        $currentType = $types->find($wechat['id']);
        $catalog->saveType(array_replace($typeInput, ['revision'=>$currentType['revision'],'enabled'=>true]), $actor);
    });
    $suite->test('只统计最终绑定流水且通知异常状态仍告警', function () use ($suite, $app): void {
        $statistics=$app->make(\app\service\ops\stat\PaymentStatisticsService::class);
        $range=$app->make(\app\http\admin\validation\OperationsQueryValidator::class)->day([]);
        $baseline=$statistics->day($range);
        $suite->same(0,$baseline['unknown_state_records']);
        $suite->true($baseline['matched_receipts']>0);
        $suite->same(0,(new \app\model\payment\ReceiptFlow())->whereNull('pay_no')->count());
        $task=(new NotifyTask())->order('id')->find();
        try {
            (new NotifyTask())->where('id',$task['id'])->update(['status'=>99]);
            $result=$statistics->day($range);
            $suite->same(1,$result['unknown_state_records']);
            $suite->same($baseline['business_amount'],$result['business_amount']);
            $suite->same($baseline['received_amount'],$result['received_amount']);
        } finally {(new NotifyTask())->where('id',$task['id'])->update(['status'=>$task['status']]);}
    });
    $suite->test('订单通知筛选贯穿列表计数与导出并保留应用边界', function () use ($suite, $app, &$state): void {
        $validator = $app->make(\app\http\admin\validation\PayOrderValidator::class);
        $orders = $app->make(\app\repository\payment\trade\BizOrderRepository::class);
        $pay = $app->make(PayOrderRepository::class)->find($state['a_order']['trade_no']);
        $task = $app->make(NotifyTaskRepository::class)->findByEvent(NotifyConstant::EVENT_PAY_SUCCESS, $pay['pay_no']);
        try {
            // 只在本测试拥有的随机库中覆盖通知调度状态；不派发 HTTP 或改变订单支付状态。
            foreach ([0,1,2,3,4] as $status) {
                (new NotifyTask())->where('notify_no', $task['notify_no'])->update(['status'=>$status]);
                $filters = $validator->filters(['biz_no'=>$pay['biz_no'],'notification_status'=>(string) $status])['filters'];
                $suite->same(1, $orders->adminCount($filters));
                $suite->same($pay['biz_no'], $orders->adminPage($filters, 1, 20)[0]['biz_no']);
                $suite->same($pay['biz_no'], $orders->exportBatch($filters, '0', 20)[0]['biz_no']);
                $suite->same(0, $orders->adminCount(array_replace($filters, ['notification_status'=>(string) (($status+1)%5)])));
                $suite->same(0, $orders->adminCount(array_replace($filters, ['notification_status'=>'none'])));
                $suite->same(0, $orders->adminCount(array_replace($filters, ['app_id'=>$state['b']['id']])));
            }
            $unpaid = $app->make(PayOrderRepository::class)->find($state['b_order']['trade_no']);
            $filters = $validator->filters(['biz_no'=>$unpaid['biz_no'],'notification_status'=>'none'])['filters'];
            $suite->same(1, $orders->adminCount($filters));
            $suite->same(0, $orders->adminCount(array_replace($filters, ['notification_status'=>'0'])));
        } finally {
            (new NotifyTask())->where('notify_no', $task['notify_no'])->update(['status'=>$task['status']]);
        }
    });

    $suite->test('整批流水匹配多单且无关重报不新增流水', function () use ($suite,$app,&$state,$create): void {
        $pays=[];$records=[];$repo=$app->make(PayOrderRepository::class);
        $group=(new \app\model\payment\PaymentPollGroup())->find($state['group']);
        $app->make(PaymentPollGroupService::class)->save($group['id'], (int)$group['revision'], $group['name'], \app\common\constant\RouteConstant::ORDER, true, '', [$state['members'][0]], ConfigurationActionContext::cli());
        $binding=(new \app\model\payment\PaymentPollGroupBind())->where('app_id',$state['b']['id'])->where('pay_type_id',$group['pay_type_id'])->find();
        $app->make(PaymentPollGroupService::class)->bind($state['b']['id'],'wxpay',$group['id'],(int)$binding['revision'],true,ConfigurationActionContext::cli());
        for($i=0;$i<3;$i++){
            $order=$create('b','batch-match-'.$i);$pay=$repo->find($order['trade_no']);$pays[]=$pay;
            $records[]=['event_key'=>'batch-'.$i,'trade_no'=>'batch-'.$i,'amount'=>$pay->receiptAmount(),
                'pay_type'=>'wxpay','paid_at'=>$app->make(Clock::class)->now()->format('Y-m-d H:i:s'),'remark'=>null];
        }
        $configuration=$app->make(PaymentPluginConfRepository::class)->find($state['configuration']);
        $scopes=$app->db->transaction(fn()=>$app->make(ReceiptScopeService::class)->describe(
            $app->make(ReceiptScopeService::class)->current($configuration,true)));
        $now=$app->make(Clock::class)->now();
        $context=new \app\service\payment\collection\ReceiptCollectionContext($configuration['id'],$configuration['account_key'],1,
            'wxpay_receipt',$app->make(PaymentPluginFactoryService::class)->make('wxpay_receipt')->getVersion(),$scopes,$state['settings'],
            $now->getTimestamp()-300,$now->getTimestamp()+1,$now->modify('+45 seconds'),hash('sha256','fixture-query'),null,hash('sha256','fixture-auth'),[$configuration['id']=>1]);
        $batch=[$records[2],['event_key'=>'unrelated','trade_no'=>'unrelated','amount'=>888888,'pay_type'=>'wxpay',
            'paid_at'=>$now->format('Y-m-d H:i:s'),'remark'=>null],$records[0],$records[1],$records[0]];
        $flows=new \app\model\payment\ReceiptFlow();$before=$flows->count();
        $callbacks=$app->make(PayOrderCallbackService::class);
        $result=$callbacks->receiveCollected($context,$batch,bin2hex(random_bytes(16)));
        $suite->same(3,$result['matched']);$suite->same(2,$result['ignored']);$suite->same($before+3,$flows->count());
        foreach($pays as $pay){$suite->same(2,$repo->find($pay['pay_no'])['status']);$suite->same(1,$flows->where('pay_no',$pay['pay_no'])->count());}
        $suite->same(0,$flows->where('event_key','unrelated')->count());
        $suite->same(3,$callbacks->receiveCollected($context,$batch,bin2hex(random_bytes(16)))['matched']);
        $suite->same($before+3,$flows->count());
        $conflict=$records[0];$conflict['amount']++;
        $suite->throws(fn()=>$callbacks->receiveCollected($context,[$records[0],$conflict],bin2hex(random_bytes(16))),\app\exception\ReceiptCollectionException::class);
        $suite->same($before+3,$flows->count());
    });
    $suite->test('同批订单并发确认保持一对一且通知失败回滚流水', function () use ($suite,$app,&$state,$create): void {
        $orders=$app->make(PayOrderRepository::class);$records=[];$pays=[];
        for($i=0;$i<2;$i++){
            $pay=$orders->find($create('b','concurrent-batch-'.$i)['trade_no']);$pays[]=$pay;
            $record=$app->make(ReceiptRecordValidator::class)->validateRecord(['event_key'=>'concurrent-'.$i,'trade_no'=>'concurrent-'.$i,
                'amount'=>$pay->receiptAmount(),'pay_type'=>'wxpay','paid_at'=>date('Y-m-d H:i:s'),'remark'=>null]);
            $records[]=['record'=>$record,'scope_key'=>$state['scope']];
        }
        $file=$app->getRuntimePath().'receipt-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($file,json_encode(['database'=>$app->config->get('database.connections.mysql.database'),
            'configuration'=>$state['configuration'],'batch'=>$records],JSON_THROW_ON_ERROR));
        $workers=[];
        try {
            for($i=0;$i<2;$i++){$process=proc_open([PHP_BINARY,__DIR__.'/receipt_worker.php','--isolated-receipt-worker',$file],
                [['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__,2));if(!is_resource($process)){throw new RuntimeException('工作进程未启动');}
                fclose($pipes[0]);$workers[]=[$process,$pipes];}
            $deadline=microtime(true)+10;
            while(count(glob($file.'.ready.*'))<2){if(microtime(true)>$deadline){throw new RuntimeException('工作进程未到达屏障');}usleep(1000);}
            file_put_contents($file.'.start','start');
            foreach($workers as [$process,$pipes]){
                $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
                $suite->same(0,proc_close($process));$suite->same('',$stderr);
                $suite->same(['selected'=>2,'matched'=>2],json_decode($stdout,true,16,JSON_THROW_ON_ERROR));
            }
        } finally {
            foreach($workers as [$process,$pipes]){if(is_resource($process)){proc_terminate($process);proc_close($process);}}
            foreach(glob($file.'.*') as $owned){unlink($owned);}unlink($file);
        }
        foreach($pays as $pay){
            $suite->same(2,$orders->find($pay['pay_no'])['status']);
            $suite->same(1,(new \app\model\payment\ReceiptFlow())->where('pay_no',$pay['pay_no'])->count());
            $suite->same(1,(new NotifyTask())->where('pay_no',$pay['pay_no'])->count());
        }
        // 仅在本测试拥有的数据库注入通知表失败，确认流水和成功状态不能先行提交。
        $pay=$orders->find($create('b','rollback-receipt')['trade_no']);
        $configuration=$app->make(PaymentPluginConfRepository::class)->find($state['configuration']);
        $scopes=$app->db->transaction(fn()=>$app->make(ReceiptScopeService::class)->describe($app->make(ReceiptScopeService::class)->current($configuration,true)));
        $plugin=$app->make(PaymentPluginFactoryService::class)->initialize('wxpay_receipt',$state['settings'],
            ['receipt_scope_key'=>$state['scope'],'channel_id'=>$pay['channel_id']]);
        $record=$app->make(ReceiptRecordValidator::class)->validateRecord(['event_key'=>'rollback-flow','trade_no'=>'rollback-flow',
            'amount'=>$pay->receiptAmount(),'pay_type'=>'wxpay','paid_at'=>date('Y-m-d H:i:s'),'remark'=>null]);
        $result=$plugin->notifyPayload(['record'=>$record]);
        $context=new ChannelNotifyContext($pay['channel_id'],$configuration['id'],$configuration['account_key'],1,'wxpay_receipt',$plugin->getVersion(),$scopes,$state['settings'],$app->make(Clock::class)->now());
        $pdo=$app->db->connect()->connect();
        $pdo->exec("CREATE TRIGGER fixture_notify_failure BEFORE INSERT ON ma_notify_task FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_failure'");
        try{$suite->throws(fn()=>$app->make(PayOrderCallbackService::class)->acceptPluginResult($result,$context,bin2hex(random_bytes(16))),\think\db\exception\PDOException::class);}
        finally{$pdo->exec('DROP TRIGGER fixture_notify_failure');}
        $suite->same($pay['status'],$orders->find($pay['pay_no'])['status']);
        $suite->same($pay->receiptAmount(),$orders->find($pay['pay_no'])->receiptAmount());
        $suite->same(0,(new \app\model\payment\ReceiptFlow())->where('event_key','rollback-flow')->count());
        $suite->same(0,(new NotifyTask())->where('pay_no',$pay['pay_no'])->count());
        $suite->same(true,$app->make(PayOrderCallbackService::class)->acceptPluginResult($result,$context,bin2hex(random_bytes(16)))['matched']);
    });
    $suite->test('商户错误正文截断保存且两个后台入口可读取', function () use ($suite,$app,&$state): void {
        $pay=$app->make(PayOrderRepository::class)->find($state['a_order']['trade_no']);
        $task=$app->make(NotifyTaskRepository::class)->findByEvent(NotifyConstant::EVENT_PAY_SUCCESS,$pay['pay_no']);
        $model=new NotifyTask();
        $model->where('notify_no','<>',$task['notify_no'])->where('status',0)->update(['next_retry_at'=>'2099-01-01 00:00:00']);
        $model->where('notify_no',$task['notify_no'])->update(['status'=>0,'next_retry_at'=>date('Y-m-d H:i:s')]);
        $body='<script>synthetic error</script>'.$task['notify_data']['sign'].str_repeat('业务处理错误',1500);
        $http=new class($body) extends \app\common\util\HttpClient {
            public function __construct(private string $body){}
            public function get(string $url,\DateTimeImmutable $deadline,?array $allowedHosts=null):\Psr\Http\Message\ResponseInterface {
                return new \GuzzleHttp\Psr7\Response(500,['Content-Type'=>'text/html'],$this->body);
            }
        };
        $dispatcher=new \app\service\payment\notify\MerchantNotifyDispatcherService($app->make(NotifyTaskRepository::class),
            $app->make(\app\repository\payment\notify\NotifyAttemptRepository::class),$app->make(\app\service\payment\notify\NotificationPolicy::class),
            $app->make(\app\service\payment\epay\EpayV1MessageService::class),$http,$app->make(Clock::class),$app->make(\app\common\util\SafeLogger::class));
        $suite->same('retry',$dispatcher->dispatchOne(bin2hex(random_bytes(16))));
        $attempt=$app->make(\app\service\payment\notify\NotifyQueryService::class)->attempts($task['notify_no'],1,20)['items'][0];
        $suite->same(500,$attempt['http_status']);$suite->same(true,$attempt['response_truncated']);
        $suite->true(strlen($attempt['response_body'])<=4096);$suite->true(mb_check_encoding($attempt['response_body'],'UTF-8'));
        $suite->true(str_starts_with($attempt['response_body'],'<script>synthetic error</script>'));
        $suite->true(!str_contains($attempt['response_body'],$task['notify_data']['sign']));
        $orderHistory=$app->make(\app\service\payment\order\PayOrderQueryService::class)->history($pay['biz_no'],'notifications',1,20);
        $suite->same($attempt['response_body'],$orderHistory['items'][0]['response_body']);
    });
    $suite->test('插件刷新持久化完整定义且无变化不重置状态或版本', function () use ($suite, $app, $actor): void {
        $model = new \app\model\payment\PaymentPlugin();
        $catalog = $app->make(\app\service\payment\config\PaymentCatalogService::class);
        $before = $model->where('code','wxpay_receipt')->find();
        $model->where('id',$before['id'])->update(['config_schema'=>'[]']);
        $catalog->refresh($actor);
        $after = $model->where('id',$before['id'])->find();
        $definition = $app->make(PaymentPluginFactoryService::class)->definition('wxpay_receipt');
        $suite->same($definition->className,$after['class_name']);
        $suite->true(\app\common\validation\PluginConfigValidator::sameSchema($definition->configSchema,$after['config_schema']));
        $suite->same($before['enabled'],$after['enabled']);
        $catalog->refresh($actor);
        $suite->same($after['revision'],$model->where('id',$before['id'])->find()['revision']);
        $columns=$app->db->connect()->connect()->query('SHOW COLUMNS FROM ma_payment_channel')->fetchAll(PDO::FETCH_COLUMN);
        foreach(['receipt_match_mode','receipt_valid_seconds','amount_offset_max','receipt_qrcode_content','receipt_qrcode_image'] as $field){$suite->true(!in_array($field,$columns,true));}
    });
    $suite->test('通道编辑不改插件参数且插件独立保存冻结新版本', function () use ($suite,$app,&$state,$actor,$create): void {
        $editor=$app->make(\app\service\payment\config\PaymentChannelEditorService::class);
        $query=$app->make(\app\service\payment\config\PaymentConfigurationQueryService::class);
        $settings=array_replace($state['settings'],['receipt_valid_seconds'=>90,'receipt_match_mode'=>'remark','amount_offset_max'=>0]);
        $created=$editor->create(['plugin_code'=>'wxpay_receipt','pay_type'=>'wxpay',
            'settings'=>$settings,'channel'=>['name'=>'独立配置通道','minimum_amount'=>1,'maximum_amount'=>1000,'daily_limit_amount'=>0,'daily_limit_count'=>0,'available_from'=>null,'available_to'=>null]],$actor);
        $detail=$query->channel($created['id']);$configuration=$query->configuration($created['configuration_id']);
        $saved=$editor->save(['id'=>$created['id'],'expected_revision'=>$detail['revision_id'],'expected_configuration_version'=>1,
            'plugin_code'=>'wxpay_receipt','pay_type'=>'wxpay','settings'=>[],
            'channel'=>['name'=>'只改通道名称','minimum_amount'=>1,'maximum_amount'=>1000,'daily_limit_amount'=>0,'daily_limit_count'=>0,'available_from'=>null,'available_to'=>null]],$actor);
        $suite->same(1,$query->configuration($created['configuration_id'])['config_version']);
        $suite->same((array)$configuration['settings'],(array)$query->configuration($created['configuration_id'])['settings']);
        $oldSnapshot=(new \app\model\payment\PaymentChannelRevision())->find($saved['revision_id'])['snapshot_json'];
        $settings['receipt_qrcode_content']='wxp://new-plugin-material';
        $result=$editor->configure(['id'=>$created['id'],'expected_revision'=>$saved['revision_id'],'expected_configuration_version'=>1,
            'settings'=>$settings,'retain_secrets'=>[]],$actor);
        $suite->same(2,$result['config_version']);
        $detail=$query->channel($created['id']);$suite->same('只改通道名称',$detail['channel']['name']);
        $suite->same(false,$detail['enabled']);$suite->true($detail['revision_id']!==$saved['revision_id']);
        $suite->same($oldSnapshot,(new \app\model\payment\PaymentChannelRevision())->find($saved['revision_id'])['snapshot_json']);
        $suite->throws(fn()=>$editor->configure(['id'=>$created['id'],'expected_revision'=>$saved['revision_id'],'expected_configuration_version'=>1,
            'settings'=>$settings,'retain_secrets'=>[]],$actor),BusinessException::class,40900);
        $editor->setEnabled(['id'=>$created['id'],'expected_revision'=>$detail['revision_id'],'expected_configuration_version'=>2,'enabled'=>true],$actor);
        $apps=$app->make(PaymentAppService::class);$id=$apps->create('插件窗口应用','1099',['merchant.example'],$actor)['id'];
        $state['c']=$app->make(PaymentAppRepository::class)->find($id);
        $state['c_key']=$app->make(PaymentAppCredentialService::class)->secretForVersion($id,$state['c']['active_key_id']);
        $groups=$app->make(PaymentPollGroupService::class);
        $group=$groups->create('插件窗口组','wxpay',\app\common\constant\RouteConstant::ORDER,'',[['channel_id'=>$created['id'],'sort_no'=>0,'weight'=>1,'is_default'=>false,'enabled'=>true]],$actor);
        $groups->bind($id,'wxpay',$group['id'],1,true,$actor);
        $pay=$app->make(PayOrderRepository::class)->find($create('c','plugin-owned-window')['trade_no']);
        $suite->same(1000,$pay->receiptAmount());$suite->same('remark',$pay['ext_json']['personal_receipt']['match_mode']);
        $duration=strtotime($pay['expire_at'])-strtotime($pay['created_at']);$suite->true($duration>=80&&$duration<=90);
    });
    $suite->test('相同平台参数可独立启用且通道可切换支付方式插件和配置', function () use ($suite,$app,$actor): void {
        $editor=$app->make(\app\service\payment\config\PaymentChannelEditorService::class);
        $query=$app->make(\app\service\payment\config\PaymentConfigurationQueryService::class);
        $rules=['name'=>'重复参数通道甲','minimum_amount'=>1,'maximum_amount'=>10000,'daily_limit_amount'=>0,'daily_limit_count'=>0,'available_from'=>null,'available_to'=>null];
        $settings=['watcher_username'=>'same-fixture-user','watcher_password'=>'synthetic-password','receipt_account_no'=>'same-fixture-account','receipt_qrcode_content'=>'https://example.invalid/fixture'];
        $first=$editor->create(['plugin_code'=>'shouqianba_receipt','pay_type'=>'alipay','settings'=>$settings,'channel'=>$rules],$actor);
        $rules['name']='重复参数通道乙';
        $second=$editor->create(['plugin_code'=>'shouqianba_receipt','pay_type'=>'alipay','settings'=>$settings,'channel'=>$rules],$actor);
        foreach([$first,$second] as $created){$editor->setEnabled(['id'=>$created['id'],'expected_revision'=>$created['revision_id'],'expected_configuration_version'=>1,'enabled'=>true],$actor);}
        $one=$query->channel($first['id']);$two=$query->channel($second['id']);
        $suite->true($one['enabled']&&$two['enabled']);$suite->true($one['configuration_id']!==$two['configuration_id']);
        $suite->same($one['channel']['scope_key'],$two['channel']['scope_key']);
        // 插件账号参数不由通用服务锁死，保存后使用新版本并停用相关通道供核对。
        $settings['receipt_account_no']='another-fixture-account';
        $saved=$editor->configure(['id'=>$first['id'],'expected_revision'=>$one['revision_id'],'expected_configuration_version'=>1,'settings'=>$settings,'retain_secrets'=>[]],$actor);
        $suite->same(2,$saved['config_version']);
        $current=$query->channel($first['id']);$snapshot=(new \app\model\payment\PaymentChannelRevision())->find($current['revision_id'])['snapshot_json'];
        $switched=$editor->save(['id'=>$first['id'],'expected_revision'=>$current['revision_id'],'expected_configuration_version'=>2,
            'plugin_code'=>'wxpay_receipt','pay_type'=>'wxpay','settings'=>['sms_forwarder_secret'=>'new-synthetic-secret','receipt_qrcode_content'=>'wxp://fixture'],
            'channel'=>$rules],$actor);
        $current=$query->channel($first['id']);$suite->same('wxpay',$current['channel']['pay_type']);
        $suite->same('wxpay_receipt',$query->configuration($switched['configuration_id'])['plugin']['code']);
        $suite->same($snapshot,(new \app\model\payment\PaymentChannelRevision())->where('channel_id',$first['id'])->where('config_version',2)->order('id','asc')->find()['snapshot_json']);
    });

    $suite->test('通道日限额限笔跳过及关闭释放并保护在途换绑', function () use ($suite,$app,$actor,&$state,$create): void {
        $editor=$app->make(\app\service\payment\config\PaymentChannelEditorService::class);
        $query=$app->make(\app\service\payment\config\PaymentConfigurationQueryService::class);
        $rules=['name'=>'限额通道','minimum_amount'=>1,'maximum_amount'=>10000,'daily_limit_amount'=>1000,'daily_limit_count'=>1,'available_from'=>null,'available_to'=>null];
        $created=$editor->create(['plugin_code'=>'wxpay_receipt','pay_type'=>'wxpay','settings'=>$state['settings'],'channel'=>$rules],$actor);
        $editor->setEnabled(['id'=>$created['id'],'expected_revision'=>$created['revision_id'],'expected_configuration_version'=>1,'enabled'=>true],$actor);
        $groups=$app->make(PaymentPollGroupService::class);$members=[['channel_id'=>$created['id'],'sort_no'=>0,'weight'=>1,'is_default'=>true,'enabled'=>true]];
        $group=$groups->create('日策略组','wxpay',\app\common\constant\RouteConstant::FIRST_AVAILABLE,'',$members,$actor);
        foreach(['d'=>'1100','e'=>'1101'] as $alias=>$pid){$id=$app->make(PaymentAppService::class)->create('限额应用'.$alias,$pid,['merchant.example'],$actor)['id'];$state[$alias]=$app->make(PaymentAppRepository::class)->find($id);$state[$alias.'_key']=$app->make(PaymentAppCredentialService::class)->secretForVersion($id,$state[$alias]['active_key_id']);$groups->bind($id,'wxpay',$group['id'],1,true,$actor);}
        $order=$create('d','quota-first');$suite->same($created['id'],$app->make(PayOrderRepository::class)->find($order['trade_no'])['channel_id']);
        $suite->throws(fn()=>$create('e','quota-blocked'),BusinessException::class,50300);
        $detail=$query->channel($created['id']);
        $suite->throws(fn()=>$editor->save(['id'=>$created['id'],'expected_revision'=>$detail['revision_id'],'expected_configuration_version'=>1,
            'plugin_code'=>'alipay_receipt','pay_type'=>'alipay','settings'=>$state['settings'],'channel'=>$rules],$actor),BusinessException::class,40900);
        $admin=new \app\service\payment\order\AdminActionContext($state['admin_id'],1,bin2hex(random_bytes(16)),'隔离测试释放日额度');
        $app->make(\app\service\payment\order\PayOrderLifecycleService::class)->closeLocally($order['trade_no'],$admin,$app->make(Clock::class)->now());
        $next=$create('e','quota-after-close');$suite->true($next['trade_no']!==$order['trade_no']);
        $app->make(\app\service\payment\order\PayOrderLifecycleService::class)->closeLocally($next['trade_no'],$admin,$app->make(Clock::class)->now());
        $state['quota_channel']=$created;$state['quota_group']=$group['id'];
    });

    $suite->test('日限额跨应用并发只接收允许的一笔', function () use ($suite,$app,&$state): void {
        $directory=$app->getRuntimePath();$file=$directory.'quota-'.bin2hex(random_bytes(8)).'.json';$workers=[];
        file_put_contents($file,json_encode(['database'=>$app->config->get('database.connections.mysql.database'),'apps'=>[$state['d']['id'],$state['e']['id']]],JSON_THROW_ON_ERROR));
        try{
            foreach([0,1] as $index){$process=proc_open([PHP_BINARY,__DIR__.'/channel_worker.php','--isolated-channel-worker',$file,(string)$index],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('并发工作进程启动失败');fclose($pipes[0]);$workers[]=[$process,$pipes];}
            $deadline=microtime(true)+15;while(count(glob($file.'.ready.*'))<2){if(microtime(true)>$deadline)throw new RuntimeException('日限额并发屏障超时');usleep(1000);}file_put_contents($file.'.start','start');
            $accepted=0;foreach($workers as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('日限额工作进程失败：'.$error);$suite->same('',$error);$accepted+=(int)json_decode($output,true,16,JSON_THROW_ON_ERROR)['accepted'];}
            $suite->same(1,$accepted);
        }finally{foreach($workers as [$process,$pipes]){if(is_resource($process))proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process))proc_close($process);}foreach(glob($file.'*') as $owned)unlink($owned);}
    });
    $suite->test('独立日限额限笔及跨午夜可用时段边界', function () use ($suite,$app,$actor,&$state): void {
        $editor=$app->make(\app\service\payment\config\PaymentChannelEditorService::class);$query=$app->make(\app\service\payment\config\PaymentConfigurationQueryService::class);
        $routes=$app->make(\app\service\payment\routing\PaymentRouteService::class);$id=$state['quota_channel']['id'];
        $update=function(array $changes)use($editor,$query,$actor,$id):void{
            $detail=$query->channel($id);$rules=$detail['channel'];unset($rules['scope_key'],$rules['pay_type']);
            $saved=$editor->save(['id'=>$id,'expected_revision'=>$detail['revision_id'],'expected_configuration_version'=>$detail['configuration_version'],
                'plugin_code'=>'wxpay_receipt','pay_type'=>'wxpay','settings'=>[],
                'channel'=>array_replace($rules,$changes)],$actor);
            $editor->setEnabled(['id'=>$id,'expected_revision'=>$saved['revision_id'],'expected_configuration_version'=>$saved['configuration_version'],'enabled'=>true],$actor);
        };
        $now=$app->make(Clock::class)->now();$route=fn(int $amount,DateTimeImmutable $time)=>$app->db->transaction(fn()=>$routes->selectFor($state['d']['id'],$amount,'wxpay',$time,false));
        $update(['daily_limit_amount'=>1500,'daily_limit_count'=>0]);
        $suite->same($id,$route(500,$now)['channel']['id']);$suite->throws(fn()=>$route(501,$now),BusinessException::class,50300);
        $update(['daily_limit_amount'=>0,'daily_limit_count'=>1]);$suite->throws(fn()=>$route(1,$now),BusinessException::class,50300);
        $suite->same($id,$route(1000,$now->modify('+1 day'))['channel']['id']);
        $update(['daily_limit_amount'=>0,'daily_limit_count'=>0,'available_from'=>'22:00','available_to'=>'06:00']);
        $tomorrow=$now->modify('+1 day');
        foreach([[22,0,true],[23,59,true],[0,0,true],[5,59,true],[6,0,false],[12,0,false],[21,59,false]] as [$h,$m,$allowed]){
            $time=$tomorrow->setTime($h,$m);if($allowed)$suite->same($id,$route(1000,$time)['channel']['id']);else $suite->throws(fn()=>$route(1000,$time),BusinessException::class,50300);
        }
    });
// 由 redesign 夹具在自己的隔离库生命周期内执行，复用已建立应用并登记独立合成插件。
$suite->test('同步插件成功走统一收款链并保存固定枚举', function () use ($suite,$app,$actor,&$state,$create): void {
    require_once dirname(__DIR__).'/fixtures/AuditPayment.php';
    $app->config->set(['test_plugins'=>['audit_payment'=>AuditPayment::class]],'payment');
    $app->make(app\service\payment\config\PaymentCatalogService::class)->refresh($actor);
    $app->make(app\service\payment\config\PaymentCatalogService::class)->pluginEnabled(['code'=>'audit_payment','revision'=>(int)$app->make(app\repository\payment\config\PaymentPluginRepository::class)->find('audit_payment')['revision'],'enabled'=>true],$actor);
    $editor=$app->make(app\service\payment\config\PaymentChannelEditorService::class);
    $channel=$editor->create(['plugin_code'=>'audit_payment','pay_type'=>'wxpay','settings'=>$state['settings'],
        'channel'=>['name'=>'合成同步插件通道','minimum_amount'=>1,'maximum_amount'=>100000,'daily_limit_amount'=>0,'daily_limit_count'=>0,'available_from'=>null,'available_to'=>null]],$actor);
    $editor->setEnabled(['id'=>$channel['id'],'expected_revision'=>$channel['revision_id'],'expected_configuration_version'=>1,'enabled'=>true],$actor);
    $id=$app->make(app\service\payment\config\PaymentAppService::class)->create('链路审查应用','1199',['merchant.example'],$actor)['id'];
    $state['audit']=$app->make(app\repository\payment\config\PaymentAppRepository::class)->find($id);
    $state['audit_key']=$app->make(app\service\payment\config\PaymentAppCredentialService::class)->secretForVersion($id,$state['audit']['active_key_id']);
    $groups=$app->make(app\service\payment\routing\PaymentPollGroupService::class);
    $group=$groups->create('链路审查组','wxpay',app\common\constant\RouteConstant::ORDER,'',[
        ['channel_id'=>$channel['id'],'sort_no'=>0,'weight'=>1,'is_default'=>true,'enabled'=>true]],$actor);
    $groups->bind($id,'wxpay',$group['id'],1,true,$actor);
    $order=$create('audit','sync-success');$state['audit_order']=$order;
    $pay=$app->make(app\repository\payment\trade\PayOrderRepository::class)->find($order['trade_no']);
    $suite->same(2,$pay['status']);$suite->same(1,$pay['route_mode']);$suite->same(1,$pay['confirmation_source']);
    $suite->same(1,(new app\model\payment\ReceiptFlow())->where('pay_no',$pay['pay_no'])->count());
    $suite->same(1,(new app\model\payment\NotifyTask())->where('pay_no',$pay['pay_no'])->count());
    $suite->same($pay['pay_no'],$create('audit','sync-success')['trade_no']);$suite->same(1,AuditPayment::$payCalls);
});

$suite->test('插件拒绝重放时租约到期也不二次调用且收银台显示处理中', function () use ($suite,$app,&$state,$create): void {
    AuditPayment::$payMode='uncertain';
    $order=$create('audit','unknown-pay');$repo=$app->make(app\repository\payment\trade\PayOrderRepository::class);
    $pay=$repo->find($order['trade_no']);$before=AuditPayment::$payCalls;
    $ext=$pay['ext_json'];$ext['payment_context']['execution']['lease_until']='2000-01-01 00:00:00';
    (new app\model\payment\PayOrder())->where('pay_no',$pay['pay_no'])->update(['ext_json'=>json_encode($ext,JSON_THROW_ON_ERROR)]);
    $app->make(app\service\payment\plugin\PayOrderChannelDispatchService::class)->dispatch($pay['pay_no'],$app->make(app\common\util\Clock::class)->now(),bin2hex(random_bytes(16)));
    $suite->same($before,AuditPayment::$payCalls);
    parse_str(parse_url($order['payurl'],PHP_URL_QUERY),$query);
    $view=$app->make(app\service\payment\cashier\CashierService::class)->view($query['biz_no'],$query['token'],$app->make(app\common\util\Clock::class)->now());
    $suite->same(true,$view['processing']);$suite->same(false,$view['can_prepare']);$suite->same(false,$view['can_pay']);
    AuditPayment::$payMode='pending';$pending=$create('audit','channel-reference');
    $suite->same('fixture-order-'.$pending['trade_no'],$repo->find($pending['trade_no'])['channel_order_no']);
    AuditPayment::$payMode='success';
});

$suite->test('退款调用原插件并保护应用归属幂等金额与未知结果', function () use ($suite,$app,&$state,$create): void {
    $protocol=$app->make(app\service\payment\epay\EpayV1ProtocolService::class);
    $base=['pid'=>'1199','key'=>$state['audit_key'],'act'=>'refund','trade_no'=>$state['audit_order']['trade_no'],'money'=>'3.00','refund_no'=>'partial-1'];
    $suite->throws(fn()=>$protocol->api(array_replace($base,['pid'=>'1002','key'=>$state['b_key']])),app\exception\BusinessException::class,40400);
    $suite->same(0,AuditPayment::$refundCalls);
    $first=$protocol->api($base);$suite->same(1,$first['code']);$suite->same(20,strlen($first['refund_no']));
    $suite->same($first,$protocol->api($base));$suite->same(1,AuditPayment::$refundCalls);
    $suite->throws(fn()=>$protocol->api(array_replace($base,['money'=>'4.00'])),app\exception\BusinessException::class,40900);
    $suite->throws(fn()=>$protocol->api(array_replace($base,['refund_no'=>'over-limit','money'=>'7.01'])),app\exception\BusinessException::class,40900);
    AuditPayment::$refundMode='unknown';
    $unknown=$protocol->api(array_replace($base,['refund_no'=>'partial-2','money'=>'2.00']));$suite->same(3,$unknown['refund_status']);
    $suite->same($unknown,$protocol->api(array_replace($base,['refund_no'=>'partial-2','money'=>'2.00'])));$suite->same(2,AuditPayment::$refundCalls);
    $suite->throws(fn()=>$protocol->api(array_replace($base,['refund_no'=>'over-unknown','money'=>'5.01'])),app\exception\BusinessException::class,40900);
    AuditPayment::$refundMode='success';
    $rest=$protocol->api(array_replace($base,['refund_no'=>'partial-3','money'=>'5.00']));$suite->same(1,$rest['code']);
    $pay=$app->make(app\repository\payment\trade\PayOrderRepository::class)->find($base['trade_no']);
    $suite->same(2,$pay['status']);$suite->same(1,(new app\model\payment\NotifyTask())->where('pay_no',$pay['pay_no'])->count());
    $detail=$app->make(app\service\payment\order\PayOrderQueryService::class)->detail($pay['biz_no']);$suite->same(3,count($detail['refunds']));
    // 无商户退款号时，一笔原订单对应一个稳定默认请求，重试不得再次调用插件。
    $default=$create('audit','default-refund');$request=array_replace($base,['trade_no'=>$default['trade_no'],'money'=>'10.00']);unset($request['refund_no']);
    $defaultResult=$protocol->api($request);$calls=AuditPayment::$refundCalls;$suite->same($defaultResult,$protocol->api($request));$suite->same($calls,AuditPayment::$refundCalls);
    foreach(['unsupported','invalid','fault'] as $mode){
        $order=$create('audit','refund-'.$mode);AuditPayment::$refundMode=$mode;
        $request=array_replace($base,['trade_no'=>$order['trade_no'],'money'=>'10.00']);
        if($mode==='unsupported'){$result=$protocol->api($request);$suite->same(2,$result['refund_status']);$suite->true(str_contains($result['msg'],'插件不支持'));}
        else{$suite->throws(fn()=>$protocol->api($request),$mode==='invalid'?UnexpectedValueException::class:RuntimeException::class);}
        $count=AuditPayment::$refundCalls;$protocol->api($request);$suite->same($count,AuditPayment::$refundCalls);
    }
    AuditPayment::$refundMode='success';
});

} finally {
    $app->db->connect('mysql')->close();
    $database->cleanup();
}
