<?php
declare(strict_types=1);

use app\common\util\BusinessNumber;
use app\exception\BusinessException;
use app\http\admin\validation\PaymentCatalogValidator;

$suite->test('业务编号固定二十位、统一时区且保留随机前导零', function () use ($suite): void {
    $engine = new class implements \Random\Engine { public function generate(): string { return str_repeat("\0", 8); } };
    $numbers = new BusinessNumber(new \Random\Randomizer($engine));
    $time = new DateTimeImmutable('2026-10-09 07:30:45', new DateTimeZone('UTC'));
    foreach (['B','P','N','R'] as $prefix) { $suite->same($prefix . '2026100915304500000', $numbers->generate($prefix, $time)); }
    $suite->throws(fn () => $numbers->generate('X', $time), InvalidArgumentException::class);
});

$suite->test('仅编号唯一索引碰撞有限重试且不吞其他重复键', function () use ($suite): void {
    $numbers = new BusinessNumber(); $time = new DateTimeImmutable('2026-10-09 15:30:45');
    $duplicate = static function (string $index): think\db\exception\PDOException {
        $native = new PDOException('synthetic duplicate');
        $native->errorInfo = ['23000',1062,"Duplicate entry 'fixture' for key 'ma_biz_order.$index'"];
        return new think\db\exception\PDOException($native);
    };
    $calls = 0;
    $result = $numbers->insert('B', $time, function (string $number) use (&$calls, $duplicate, $suite): void {
        $calls++; $suite->same(20, strlen($number));
        if ($calls === 1) { throw $duplicate('uk_biz_no'); }
    });
    $suite->same(2, $calls); $suite->same(20, strlen($result));
    $calls = 0;
    $otherIndex = function () use (&$calls, $duplicate): void { $calls++; throw $duplicate('uk_biz_merchant_order'); };
    $suite->throws(fn () => $numbers->insert('B', $time, $otherIndex), think\db\exception\PDOException::class);
    $suite->same(1, $calls);
    $calls = 0;
    $alwaysDuplicate = function () use (&$calls, $duplicate): void { $calls++; throw $duplicate('uk_biz_no'); };
    $suite->throws(fn () => $numbers->insert('B', $time, $alwaysDuplicate), BusinessException::class, 50300);
    $suite->same(5, $calls);
});

$suite->test('支付方式维护拒绝宽松类型和任意资源路径', function () use ($suite): void {
    $validator = new PaymentCatalogValidator();
    $input = ['id'=>null,'revision'=>0,'code'=>'unionpay','name'=>'云闪付','sort_order'=>3,'enabled'=>false,'icon_asset_id'=>null,'logo_asset_id'=>null];
    $suite->same($input, $validator->validate('save', $input));
    foreach ([['enabled'=>'false'],['sort_order'=>'3'],['code'=>'../bad'],['icon_asset_id'=>'https://example.com/icon.png'],['name'=>'<script>']] as $change) {
        $suite->throws(fn () => $validator->validate('save', array_replace($input, $change)), BusinessException::class, 42200);
    }
});

$suite->test('查询入口接受新编号并保持独立访问凭证校验', function () use ($suite, $app): void {
    $cashier = new app\http\api\validation\CashierValidator();
    $identity = ['biz_no' => 'B2026100915304505189', 'token' => str_repeat('a', 64)];
    $suite->same($identity, $cashier->identity($identity));
    $suite->throws(fn () => $cashier->identity(array_replace($identity, ['token' => 'bad'])), BusinessException::class, 40300);
    $suite->throws(fn () => $cashier->identity(array_replace($identity, ['biz_no' => '../order'])), BusinessException::class, 40300);
    $orders = $app->make(app\http\admin\validation\PayOrderValidator::class);
    $suite->same($identity['biz_no'], $orders->identity(['biz_no' => $identity['biz_no']]));
    $suite->same('P2026100915304505189', $orders->filters(['pay_no' => 'P2026100915304505189'])['filters']['pay_no']);
    $queries = $app->make(app\http\admin\validation\OperationsQueryValidator::class);
    $suite->same('N2026100915304505189', $queries->identity('notify_no', ['notify_no' => 'N2026100915304505189'])['key']);
});
