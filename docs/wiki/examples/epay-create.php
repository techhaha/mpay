<?php
declare(strict_types=1);

/** CLI example: generate a signed checkout URL, without sending any payment request. */
use Mpay\Docs\Example\EpaySignature;

require __DIR__ . '/epay-sign.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$gateway = rtrim(getenv('MPAY_GATEWAY') ?: 'https://pay.example.com', '/');
$pid = getenv('MPAY_PID') ?: '';
$key = getenv('MPAY_KEY') ?: '';
$merchant = rtrim(getenv('SHOP_URL') ?: 'https://shop.example.com', '/');
$orderNo = $argv[1] ?? '';
if ($pid === '' || $key === '' || $orderNo === '' || str_contains($gateway, 'example.com') || str_contains($merchant, 'example.com')) {
    fwrite(STDERR, "Set MPAY_GATEWAY, MPAY_PID, MPAY_KEY and SHOP_URL; run: php epay-create.php YOUR_ORDER_NO\n");
    exit(1);
}

// Use the original merchant order number on retries. All signed values are strings.
$parameters = [
    'pid' => $pid,
    'type' => 'alipay',
    'out_trade_no' => $orderNo,
    'notify_url' => $merchant . '/notify.php',
    'return_url' => $merchant . '/return.php',
    'name' => '接入示例订单',
    'money' => '1.00',
    'param' => 'example',
];
$parameters['sign'] = EpaySignature::sign($parameters, $key);
$parameters['sign_type'] = 'MD5';
echo $gateway . '/submit.php?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986) . PHP_EOL;
