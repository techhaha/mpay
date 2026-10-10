<?php
declare(strict_types=1);

use app\common\sdk\alipay\AlipaySigner;
use app\common\util\CliWorkerControl;
use app\common\validation\PluginConfigValidator;
use app\exception\BusinessException;
use app\exception\ReceiptCollectionException;
use app\http\admin\validation\PaymentAppValidator;
use app\http\admin\validation\PaymentPollGroupValidator;
use app\service\payment\plugin\PaymentPluginFactoryService;
use app\service\payment\receipt\ReceiptScopeSet;
use app\service\payment\collection\ReceiptCollectionCursor;

// 本文件仅在用户显式运行 tests/run.php 时执行；开发交付只做语法及清单加载检查。
$suite->test('schema 拒绝未知属性且条件必填和零值保持原生规则', function () use ($suite): void {
    $validator = new PluginConfigValidator();
    $schema = [
        ['field'=>'text','type'=>'input','title'=>'文本','validate'=>['requireWithout'=>'image']],
        ['field'=>'image','type'=>'upload','title'=>'图片','validate'=>['requireWithout'=>'text']],
        ['field'=>'count','type'=>'number','title'=>'数量','validate'=>['between'=>[0,10]],'value'=>0],
        ['field'=>'active','type'=>'switch','title'=>'开关','validate'=>[],'value'=>false],
    ];
    $suite->same(['text'=>'only text','count'=>0,'active'=>false], $validator->validate($schema,['text'=>'only text'],true));
    $suite->throws(fn()=>$validator->validate($schema,[],true),BusinessException::class);
    $suite->throws(fn()=>$validator->validate($schema,['text'=>'ok','count'=>null],true),BusinessException::class);
    $bad=$schema;$bad[0]['props']=['rows'=>3];$suite->throws(fn()=>PluginConfigValidator::assertSchema($bad),LogicException::class);
    $bad=$schema;$bad[0]['field']='channel_id';$suite->throws(fn()=>PluginConfigValidator::assertSchema($bad),LogicException::class);
    $bad=$schema;$bad[0]['visible_when']=['field'=>'count','value'=>'0'];$suite->throws(fn()=>PluginConfigValidator::assertSchema($bad),LogicException::class);
});

$suite->test('应用和轮询组拒绝额外字段及宽松 JSON 类型', function () use ($suite): void {
    $apps = new PaymentAppValidator();
    $input = ['name' => '合成应用', 'pid' => '', 'allowed_hosts' => []];
    $suite->same($input, $apps->input('create', $input));
    $suite->throws(fn () => $apps->input('create', $input + ['merchant_id' => '1']), BusinessException::class);
    $groups = new PaymentPollGroupValidator();
    $member = ['channel_id' => '1', 'sort_no' => 0, 'weight' => 1, 'is_default' => false, 'enabled' => true];
    $suite->same($member, $groups->input('member', $member));
    $suite->throws(fn () => $groups->input('member', array_replace($member, ['weight' => '1'])), BusinessException::class);
    $suite->throws(fn () => $groups->input('member', array_replace($member, ['enabled' => 1])), BusinessException::class);
});

$suite->test('插件独立实例及字段列表秘密边界', function () use ($suite, $app): void {
    $factory = $app->make(PaymentPluginFactoryService::class);
    $first = $factory->make('wxpay_receipt'); $second = $factory->make('wxpay_receipt');
    $suite->true($first !== $second);
    $validator = new PluginConfigValidator();
    $schema = $first->getConfigSchema();
    $values = $validator->validate($schema, ['sms_forwarder_secret' => 'synthetic-secret-only', 'receipt_qrcode_content' => 'wxp://synthetic'], true);
    $suite->same(300, $values['sms_forwarder_time_tolerance']);
    $public = $validator->publicValues($schema, $values);
    $suite->true(!isset($public['values']['sms_forwarder_secret']));
    $suite->same(true, $public['secret_present']['sms_forwarder_secret']);
    $suite->throws(fn () => $validator->validate($schema, array_replace($values, ['sms_forwarder_time_tolerance' => '300'])), BusinessException::class);
    $suite->throws(fn () => $validator->validate($schema, $values + ['source' => 'wechat_pc']), BusinessException::class);
});

$suite->test('支付宝请求签名与响应原文验签拒绝篡改及重复顶层节点', function () use ($suite): void {
    // Windows PHP 未必配置全局 openssl.cnf；测试自带最小配置，不依赖开发者机器路径。
    $options = ['config' => dirname(__DIR__) . '/fixtures/openssl.cnf',
        'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = openssl_pkey_new($options);
    if ($key === false || !openssl_pkey_export($key, $private, null, $options)) { throw new RuntimeException('隔离 RSA 夹具生成失败'); }
    $public = openssl_pkey_get_details($key)['key']; $signer = new AlipaySigner();
    $signature = $signer->sign(['b' => '2', 'sign_type' => 'RSA2', 'a' => '1'], $private);
    $suite->same(1, openssl_verify('a=1&b=2&sign_type=RSA2', base64_decode($signature, true), $public, OPENSSL_ALGO_SHA256));
    $body = '{"code":"10000", "page_no":"1","page_size":"200","total_size":"0","detail_list":[]}';
    openssl_sign($body, $rawSign, $private, OPENSSL_ALGO_SHA256);
    $name = 'alipay_data_bill_accountlog_query_response';
    $envelope = '{"' . $name . '":' . $body . ',"sign":' . json_encode(base64_encode($rawSign), JSON_THROW_ON_ERROR) . '}';
    $suite->same($body, $signer->response($envelope, $public));
    $suite->throws(fn () => $signer->response(str_replace('10000', '10001', $envelope), $public), ReceiptCollectionException::class);
    $duplicate = '{"' . $name . '":' . $body . ',' . substr($envelope, 1);
    $suite->throws(fn () => $signer->response($duplicate, $public), ReceiptCollectionException::class);
});

$suite->test('完成游标绑定真实范围且拒绝损坏身份', function () use ($suite): void {
    $scopes = new ReceiptScopeSet(['scope_fixture' => ['pay_type' => 'alipay']]);
    $cursor = ReceiptCollectionCursor::create('account_fixture', 'alipay_bill_receipt', $scopes, 100, 200);
    $suite->same($cursor, ReceiptCollectionCursor::read('account_fixture', 'alipay_bill_receipt', $cursor));
    $suite->throws(fn () => ReceiptCollectionCursor::read('another_account', 'alipay_bill_receipt', $cursor), ReceiptCollectionException::class);
    $suite->throws(fn () => ReceiptCollectionCursor::read('account_fixture', 'alipay_bill_receipt', array_replace($cursor, ['completed_through' => 99])), ReceiptCollectionException::class);
});

$suite->test('同站点角色锁阻止重复工作者且退出后释放', function () use ($suite, $app): void {
    $directory = $app->getRuntimePath() . 'worker-lock-' . bin2hex(random_bytes(8));
    $first = new CliWorkerControl(); $second = new CliWorkerControl();
    try {
        $first->acquire($directory, 'work');
        $suite->throws(fn () => $second->acquire($directory, 'work'), RuntimeException::class);
        $first->restore();
        $second->acquire($directory, 'work');
        $suite->true(true);
    } finally {
        $first->restore(); $second->restore();
        if (is_file($directory . '/workers/work.lock')) { unlink($directory . '/workers/work.lock'); }
        if (is_dir($directory . '/workers')) { rmdir($directory . '/workers'); }
        if (is_dir($directory)) { rmdir($directory); }
    }
});

$suite->test('插件 schema 自主定义字段且拒绝空收款材料', function () use ($suite,$app): void {
    $validator = new PluginConfigValidator();
    $suite->same([], $validator->validate([], [], true));
    $schema = $app->make(PaymentPluginFactoryService::class)->definition('alipay_receipt')->configSchema;
    $input = ['sms_forwarder_secret'=>'synthetic-secret-only'];
    $suite->throws(fn()=>$validator->validate($schema,$input,true), BusinessException::class,42200);
    $values=$validator->validate($schema,$input+['receipt_qrcode_content'=>'alipay://synthetic'],true);
    $suite->same(300,$values['receipt_valid_seconds']);$suite->same(99,$values['amount_offset_max']);
    $values['receipt_valid_seconds']=0;$suite->throws(fn()=>$validator->validate($schema,$values),BusinessException::class,42200);
    $channel=new \app\service\payment\config\PaymentChannelInput('通用通道','scope-test','alipay');
    $suite->true(!array_key_exists('receipt_valid_seconds',$channel->record()));
    $suite->same(0,$channel->dailyLimitAmount);$suite->same(null,$channel->availableFrom);
    $suite->throws(fn()=>new \app\service\payment\config\PaymentChannelInput('时段缺失','scope-test','alipay',availableFrom:'22:00'),InvalidArgumentException::class);
    $suite->throws(fn()=>new \app\service\payment\config\PaymentChannelInput('时段相同','scope-test','alipay',availableFrom:'06:00',availableTo:'06:00'),InvalidArgumentException::class);
});

$suite->test('流水合同接受自定义支付方式并严格限定文本字节', function () use ($suite): void {
    $validator=new app\service\payment\receipt\ReceiptRecordValidator();
    $record=['pay_type'=>'unionpay','event_key'=>'fixture','trade_no'=>null,'amount'=>100,'paid_at'=>'2026-10-09 12:00:00','remark'=>null];
    $suite->same('unionpay',$validator->validateRecord($record)['pay_type']);
    $suite->throws(fn()=>$validator->validateRecord(array_replace($record,['event_key'=>str_repeat('字',50)])),UnexpectedValueException::class);
    $suite->throws(fn()=>$validator->validateRecord(array_replace($record,['pay_type'=>'../bad'])),UnexpectedValueException::class);
    $suite->throws(fn()=>$validator->validateRecord(array_replace($record,['amount'=>'100'])),UnexpectedValueException::class);
});
