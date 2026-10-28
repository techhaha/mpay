<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\ApiResponseConstant;
use app\common\interface\PaymentInterface;
use app\common\sdk\epay\EpayV1Client;
use app\common\sdk\epay\EpayV1Signer;
use app\common\util\HttpTargetPolicy;
use app\common\util\Money;
use app\common\util\Clock;
use app\exception\BusinessException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use InvalidArgumentException;
use think\Request;
use think\Response;
use think\Validate;

/** 通过易支付 V1 对接第三方平台；只处理 API 和签名通知，不参加手机监听或账单采集。 */
final class EpayV1Payment extends BasePayment implements PaymentInterface
{
    protected array $paymentInfo = [
        'code' => 'epay_v1',
        'name' => '易支付 V1',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay'],
        'description' =>
            '填写第三方易支付 V1 平台的网关、商户 PID 和 KEY。默认使用 submit.php 页面支付，也可选择 mapi.php 接口下单。平台须能访问本系统回调地址。退款需先在第三方商户后台开启接口退款。',
        'config_schema' => [
            [
                'type' => 'input',
                'field' => 'gateway',
                'title' => '第三方网关地址',
                'validate' => [0 => 'require', 1 => 'url', 'max' => 2048],
                'placeholder' => 'https://pay.example.com/',
                'help' =>
                    '填写站点根地址或安装子目录，不填写 submit.php、mapi.php、api.php 或查询参数。',
            ],
            [
                'type' => 'input',
                'field' => 'pid',
                'title' => '第三方商户 PID',
                'validate' => [0 => 'require', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
            ],
            [
                'type' => 'input',
                'field' => 'key',
                'title' => '第三方商户 KEY',
                'validate' => [0 => 'require', 1 => 'string', 'max' => 256],
                'secret' => true,
            ],
            [
                'type' => 'select',
                'field' => 'pay_method',
                'title' => '下单方式',
                'options' => [
                    ['value' => 'submit', 'label' => '页面支付（submit.php）'],
                    ['value' => 'mapi', 'label' => 'API 下单（mapi.php）'],
                ],
                'validate' => [0 => 'require', 'in' => ['submit', 'mapi']],
                'value' => 'submit',
                'help' =>
                    '默认进入第三方收银台付款；选择 mapi 时，由本站请求第三方接口取得付款信息。',
            ],
        ],
    ];

    public function __construct(
        private readonly EpayV1Client $client,
        private readonly EpayV1Signer $signer,
        private readonly Clock $clock,
    ) {}

    public function pay(array $order): array
    {
        $method = $this->getConfig('pay_method');
        if (!in_array($method, ['submit', 'mapi'], true)) {
            throw new PaymentDefinitiveException('第三方下单方式无效');
        }
        $parameters = [
            'pid' => $this->configuration['pid'],
            'type' => $order['pay_type_code'],
            'out_trade_no' => $order['pay_no'],
            'notify_url' => $order['notify_url'],
            // 回到本地收银台继续查询；浏览器回跳不确认成功，也不绕过原商户通知责任。
            'return_url' => $order['return_url'],
            // 建单入口已按协议截断并冻结商品名；转发时使用原单值。
            'name' => $order['subject'],
            'money' => Money::format($order['amount']),
            'sign_type' => 'MD5',
        ];
        if ($method === 'submit') {
            $parameters['sign'] = $this->signer->sign($parameters, $this->configuration['key']);
            // 只生成原单的浏览器链接；没有上游受理号时不使用本地号占位。
            $url =
                EpayV1Client::gateway($this->configuration['gateway']) .
                'submit.php?' .
                http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
            return $this->pendingResult($order, 'submit', null, [
                'pay_page' => 'jump',
                'pay_params' => ['url' => $url],
            ]);
        }
        $parameters['clientip'] = $order['client_ip'];
        $parameters['device'] = $order['_env'];
        $parameters['sign'] = $this->signer->sign($parameters, $this->configuration['key']);
        $data = $this->client->request($this->configuration['gateway'], 'mapi.php', $parameters);
        $this->assertAccepted($data, 1);
        if (!$this->reference($data['trade_no'] ?? null)) {
            throw new PaymentUncertainException('第三方下单响应缺少平台订单号');
        }
        $materials = array_filter(
            [
                'payurl' => $data['payurl'] ?? null,
                'qrcode' => $data['qrcode'] ?? null,
                'urlscheme' => $data['urlscheme'] ?? null,
            ],
            static fn(mixed $value): bool => $value !== null && $value !== '',
        );
        // 上游可以同时返回多种材料；沿用 V2 优先顺序，仅消费并校验首个非空材料。
        if ($materials === [] || !is_string(reset($materials))) {
            throw new PaymentUncertainException('第三方下单响应的付款材料无效');
        }
        $kind = array_key_first($materials);
        $value = $materials[$kind];
        if ($kind === 'qrcode') {
            if (strlen($value) > 2331 || !mb_check_encoding($value, 'UTF-8')) {
                throw new PaymentUncertainException('第三方返回的二维码内容超限或编码无效');
            }
            $presentation = [
                'pay_page' => 'page',
                'pay_params' => ['_page' => 'receiptQrcode', 'qrcode_content' => $value],
            ];
        } else {
            try {
                if ($kind === 'payurl') {
                    HttpTargetPolicy::uri($value, HttpTargetPolicy::MAX_REQUEST_URL_LENGTH);
                } elseif (
                    strlen($value) > 16384 ||
                    preg_match('/[\x00-\x20\x7f]/', $value) ||
                    !in_array(
                        parse_url($value, PHP_URL_SCHEME),
                        ['alipay', 'alipays', 'weixin'],
                        true,
                    )
                ) {
                    throw new InvalidArgumentException('唤起地址无效');
                }
            } catch (BusinessException | InvalidArgumentException) {
                throw new PaymentUncertainException('第三方返回的付款地址无效');
            }
            $presentation = ['pay_page' => 'jump', 'pay_params' => ['url' => $value]];
        }
        return $this->pendingResult($order, 'mapi', $data['trade_no'], $presentation);
    }

    /**
     * 两种准备方式共用标准 pending 结果；仅上游实际返回订单号时记录，材料不代表已付款。
     *
     * @param array<string,mixed> $order 原支付单参数。
     * @param array{pay_page:string,pay_params:array<string,mixed>} $presentation 公开付款材料。
     * @return array<string,mixed>
     */
    private function pendingResult(
        array $order,
        string $action,
        ?string $channelOrderNo,
        array $presentation,
    ): array {
        return [
            'status' => 'pending',
            'pay_no' => $order['pay_no'],
            'paid_amount' => null,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => null,
            'pay_type' => $order['pay_type_code'],
            'pay_product' => 'epay_v1',
            'pay_action' => $action,
            'presentation' => $presentation,
        ];
    }

    /** 按本地支付号查原平台订单；查询失败只表示状态未知，不作为付款被拒绝的证据。 */
    public function query(array $order): array
    {
        $data = $this->client->request(
            $this->configuration['gateway'],
            'api.php',
            [
                'act' => 'order',
                'pid' => $this->configuration['pid'],
                'key' => $this->configuration['key'],
                'out_trade_no' => $order['pay_no'],
            ],
            true,
        );
        if ($data['code'] !== 1) {
            throw new PaymentUncertainException($this->responseError($data));
        }
        if (
            ($data['out_trade_no'] ?? null) !== $order['pay_no'] ||
            (string) ($data['pid'] ?? '') !== $this->configuration['pid'] ||
            !in_array($data['status'] ?? null, [0, 1, '0', '1'], true)
        ) {
            throw new PaymentUncertainException('第三方查询响应的订单身份或状态无效');
        }
        $result = $this->paymentResult($data, $this->clock->now()->format('Y-m-d H:i:s'));
        if (
            $result['pay_type'] !== $order['pay_type_code'] ||
            $result['paid_amount'] !== $order['amount']
        ) {
            throw new PaymentUncertainException('第三方查询响应与原订单金额或方式不一致');
        }
        if ((int) $data['status'] === 0) {
            $result['status'] = 'pending';
            $result['paid_amount'] = null;
            $result['received_amount'] = null;
            $result['paid_at'] = null;
        } elseif (isset($data['endtime']) && $data['endtime'] !== '') {
            if (
                !(new Validate())
                    ->rule(['endtime' => ['require', 'dateFormat' => 'Y-m-d H:i:s']])
                    ->check($data)
            ) {
                throw new PaymentUncertainException('第三方查询响应的付款时间无效');
            }
            $result['paid_at'] = $data['endtime'];
        }
        return $result;
    }

    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException();
    }

    /**
     * 按 V1 将 act 放在 URL、凭证及退款参数放在 POST 表单。
     * 平台未提供退款请求幂等号；公共退款认领保留，网络未知时不得自动再次调用。
     */
    public function refund(array $order): array
    {
        $data = $this->client->request($this->configuration['gateway'], 'api.php?act=refund', [
            'pid' => $this->configuration['pid'],
            'key' => $this->configuration['key'],
            'out_trade_no' => $order['pay_no'],
            'money' => Money::format($order['refund_amount']),
        ]);
        $this->assertAccepted($data, 0);
        return [
            'status' => 'success',
            'refund_no' => $order['refund_no'],
            'pay_no' => $order['pay_no'],
            'refund_amount' => $order['refund_amount'],
            'chan_refund_no' => null,
        ];
    }

    /** GET 使用实际 URL 的 query，避开伪静态注入参数；POST 只读表单，不合并两种输入。 */
    public function notify(Request|array $payload, array $context): array
    {
        if (!($payload instanceof Request)) {
            throw new UnsupportedPaymentOperationException();
        }
        $request = $payload;
        if ($request->isGet()) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
        } elseif ($request->isPost()) {
            $parameters = $request->post();
        } else {
            throw new BusinessException('第三方通知方法无效', ApiResponseConstant::BAD_REQUEST);
        }
        foreach ($parameters as $value) {
            if (!is_string($value)) {
                throw new BusinessException(
                    '第三方通知参数格式无效',
                    ApiResponseConstant::BAD_REQUEST,
                );
            }
        }
        if (!$this->signer->verify($parameters, $this->configuration['key'])) {
            throw new BusinessException('第三方通知签名无效', ApiResponseConstant::FORBIDDEN);
        }
        if (
            ($parameters['pid'] ?? null) !== $this->configuration['pid'] ||
            ($parameters['trade_status'] ?? null) !== 'TRADE_SUCCESS' ||
            (isset($context['pay_no']) &&
                ($parameters['out_trade_no'] ?? null) !== $context['pay_no'])
        ) {
            throw new BusinessException(
                '第三方通知的商户、订单或支付状态不一致',
                ApiResponseConstant::FORBIDDEN,
            );
        }
        return [$this->paymentResult($parameters, $context['received_at'])];
    }

    /** 平台要求原样纯文本，避免 Accept: application/json 把 success 编码为带引号的 JSON。 */
    public function notifySuccess(): Response
    {
        return response('success', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function notifyFail(string $message): Response
    {
        return response('fail', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * 平台 trade_no 是受理订单号，不复制为真实流水号；原参数原样供回调记录使用。
     * 标准 notify 无付款时间，使用服务提供的本次接收时间；生命周期保留首次确认时间。
     *
     * @return array<string,mixed>
     */
    private function paymentResult(array $data, string $receivedAt): array
    {
        $valid = (new Validate())
            ->rule([
                'out_trade_no' => ['require', 'string', 'regex' => '/^P[0-9]{19}$/D'],
                'type' => ['require', 'string', 'in' => $this->getEnabledPayTypes()],
                'money' => ['require', 'string'],
            ])
            ->check($data);
        if (!$valid || !$this->reference($data['trade_no'] ?? null)) {
            throw new BusinessException('第三方订单字段无效', ApiResponseConstant::BAD_REQUEST);
        }
        try {
            $amount = Money::parse($data['money']);
        } catch (InvalidArgumentException) {
            throw new BusinessException('第三方订单金额格式无效', ApiResponseConstant::BAD_REQUEST);
        }
        if ($amount < 1) {
            throw new BusinessException(
                '第三方订单金额必须大于零',
                ApiResponseConstant::BAD_REQUEST,
            );
        }
        return [
            'status' => 'success',
            'pay_no' => $data['out_trade_no'],
            'paid_amount' => $amount,
            'received_amount' => $amount,
            'pay_type' => $data['type'],
            'chan_order_no' => $data['trade_no'],
            'chan_trade_no' => null,
            'paid_at' => $receivedAt,
            'event_key' => null,
            'raw' => $data,
        ];
    }

    private function reference(mixed $value): bool
    {
        return is_string($value) &&
            $value !== '' &&
            strlen($value) <= 128 &&
            mb_check_encoding($value, 'UTF-8') &&
            !preg_match('/[\x00-\x20\x7f]/', $value);
    }

    /** 下单 code=1，退款 code=0；动作被明确拒绝时抛出确定失败，不用于查询结果。 */
    private function assertAccepted(array $data, int $successCode): void
    {
        if ($data['code'] !== $successCode) {
            throw new PaymentDefinitiveException($this->responseError($data));
        }
    }

    /** 共用平台错误的脱敏和长度限制；异常类别由当前动作决定。 */
    private function responseError(array $data): string
    {
        $message = is_string($data['msg'] ?? null) ? $data['msg'] : '请求未成功';
        $message = str_replace($this->configuration['key'], '[已隐藏]', $message);
        return '第三方平台：' . mb_strcut($message, 0, 512, 'UTF-8');
    }
}
