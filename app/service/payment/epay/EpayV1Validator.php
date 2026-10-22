<?php
declare(strict_types=1);

namespace app\service\payment\epay;

use app\common\constant\ApiResponseConstant;
use app\common\util\Money;
use app\common\validation\PaymentTypeCode;
use app\exception\BusinessException;
use app\service\payment\order\CreateOrderInput;
use InvalidArgumentException;
use LogicException;
use think\Validate;

/** 易支付原生字段/场景验证；下单必须先验证原始表单签名，再调用本类截断名称和转换金额。 */
final class EpayV1Validator extends Validate
{
    /** @var array<string,array> 表单规则只校验、不过滤值；未知签名字段不进入内部业务输入。 */
    protected $rule = [
        'pid' => ['require', 'string', 'regex' => '/^[1-9][0-9]{0,31}$/D'],
        'out_trade_no' => ['require', 'string', 'max' => 64],
        'name' => ['require', 'string'],
        'money' => ['require', 'string'],
        'type' => ['string', 'regex' => PaymentTypeCode::PATTERN],
        'notify_url' => ['require', 'string', 'max:2048'],
        'return_url' => ['string', 'max:2048'],
        // 原生 ip 规则默认只接受 IPv4；filter 规则不限定地址族，符合本接口同时接受 IPv4/IPv6 的合同。
        'clientip' => ['string', 'filter' => FILTER_VALIDATE_IP],
        'device' => ['string', 'in' => ['pc', 'mobile', 'qq', 'wechat', 'alipay', 'jump']],
        'param' => ['string', 'byteLength' => 2048],
        'refund_no' => ['string', 'regex' => '/^[A-Za-z0-9_-]{1,64}$/D'],
        'trade_no' => ['require', 'string', 'byteLength' => 64],
        'page' => [
            'require',
            'string',
            'regex' => '/^[1-9][0-9]{0,6}$/D',
            'between' => [1, 1000000],
        ],
        'limit' => ['require', 'string', 'regex' => '/^[1-9][0-9]{0,6}$/D', 'between' => [1, 50]],
    ];
    protected $message = [
        'pid' => 'PID 必须为有效正整数',
        'out_trade_no' => '外部订单号必须为 1～64 个字符',
        'name' => '订单名称不能为空且必须为字符串',
        'money' => '金额必须为有效的正数元字符串，最多两位小数',
        'type' => '请选择支持的支付方式',
        'notify_url' => '必须提供有效通知地址',
        'return_url' => '页面下单必须提供有效回跳地址',
        'clientip' => '必须提供有效 IPv4 或 IPv6 地址',
        'device' => '设备类型不受支持',
        'param' => '扩展参数不能超过 2048 字节',
        'trade_no' => '支付单号必须为 1～64 字节字符串',
        'page' => '分页参数超出范围',
        'limit' => '分页参数超出范围',
    ];
    protected $scene = [
        'pid' => ['pid'],
        'query_trade' => ['trade_no'],
        'query_external' => ['out_trade_no'],
        'pagination' => ['page', 'limit'],
        'refund' => ['money', 'refund_no'],
    ];

    /** 页面下单允许暂不选方式，但必须提供回跳地址；其余字段规则保持共用。 */
    protected function sceneSubmit(): void
    {
        $this->only([
            'out_trade_no',
            'name',
            'money',
            'type',
            'notify_url',
            'return_url',
            'clientip',
            'device',
            'param',
        ])->append('return_url', 'require');
    }

    /** API 下单必须明确方式和付款端 IP，回跳地址可以省略。 */
    protected function sceneMapi(): void
    {
        $this->only([
            'out_trade_no',
            'name',
            'money',
            'type',
            'notify_url',
            'return_url',
            'clientip',
            'device',
            'param',
        ])
            ->append('type', 'require')
            ->append('clientip', 'require');
    }

    /** @param array<string|int,string> $input 原始标量表单，不修改待签名字节。 */
    public function pid(array $input): string
    {
        $this->assertScene('pid', $input);
        return $input['pid'];
    }

    /**
     * 原始验签成功后才进入此方法，未声明标量不自动保存到 CreateOrderInput/ext_json。
     * 金额的十进制定点转换、目标授权属于项目合同，不交给宽松 number/url 规则代替。
     *
     * @param array<string|int,string> $input 已完成原始参数验签的表单。
     * @param string $scene submit 或 mapi。
     * @param string|null $detectedDevice 页面识别环境；mapi 传 null，缺省 device=pc 在本入口处理。
     * @throws BusinessException 字段、金额或目标政策拒绝；尚未创建业务订单。
     */
    public function order(
        array $input,
        string $scene,
        string $appId,
        string $versionId,
        ?string $requestIp,
        ?string $detectedDevice,
    ): CreateOrderInput {
        if (!in_array($scene, ['submit', 'mapi'], true)) {
            throw new LogicException('未知易支付下单场景');
        }
        $this->assertScene($scene, $input);
        try {
            $amount = Money::parse($input['money']);
        } catch (InvalidArgumentException) {
            $this->invalid('money', '金额必须为有效的正数元字符串，最多两位小数');
        }
        if ($amount < 1) {
            $this->invalid('money', '付款金额必须大于零');
        }
        $type = ($input['type'] ?? '') === '' ? null : $input['type'];
        $notify = $input['notify_url'];
        $return = ($input['return_url'] ?? '') === '' ? null : $input['return_url'];
        // 原生场景已校验 URL 字段类型/长度；完整建单入口统一检查地址、应用授权及签名 query。
        $clientIp = ($input['clientip'] ?? '') === '' ? null : $input['clientip'];
        if (
            $clientIp === null &&
            $requestIp !== null &&
            filter_var($requestIp, FILTER_VALIDATE_IP) !== false
        ) {
            $clientIp = $requestIp;
        }
        // mapi 的调用者通常是服务器，不能用其 UA 猜付款设备；submit 只采用浏览器识别结果。
        $device = $scene === 'mapi' ? (($input['device'] ?? '') === '' ? 'pc' : $input['device']) : null;
        return new CreateOrderInput(
            appId: $appId,
            pid: $input['pid'],
            credentialVersionId: $versionId,
            merchantOrderNo: $input['out_trade_no'],
            subject: mb_strcut($input['name'], 0, 127, 'UTF-8'),
            amount: $amount,
            requestedPayType: $type,
            notifyUrl: $notify,
            returnUrl: $return,
            clientIp: $clientIp,
            device: $device,
            param: $input['param'] ?? '',
            detectedDevice: $scene === 'submit' ? $detectedDevice : null,
            submitType: $scene === 'submit' ? 'page' : 'api',
        );
    }

    /**
     * trade_no 优先；存在有效 trade_no 时不再用 out_trade_no 选择另一笔订单。
     *
     * @param array<string|int,string> $input
     * @return array{trade_no:?string,out_trade_no:?string}
     */
    public function queryIdentity(array $input): array
    {
        if (($input['trade_no'] ?? '') !== '') {
            $this->assertScene('query_trade', $input);
            return ['trade_no' => $input['trade_no'], 'out_trade_no' => null];
        }
        $this->assertScene('query_external', $input);
        return ['trade_no' => null, 'out_trade_no' => $input['out_trade_no']];
    }

    /**
     * @param array<string|int,string> $input
     * @return array{page:int,limit:int} 易支付批量查询上限为 50，不复用后台的 100。
     */
    public function pagination(array $input): array
    {
        $values = ['page' => $input['page'] ?? '1', 'limit' => $input['limit'] ?? '20'];
        $this->assertScene('pagination', $values);
        return ['page' => (int) $values['page'], 'limit' => (int) $values['limit']];
    }

    /** @param array<string|int,string> $input 已认证的原生表单。退款金额为正整数分。 */
    public function refund(array $input): array
    {
        if (($input['refund_no'] ?? '') === '') {
            unset($input['refund_no']);
        }
        $this->assertScene('refund', $input);
        try {
            $amount = Money::parse($input['money']);
        } catch (InvalidArgumentException) {
            $this->invalid('money', '退款金额必须为有效正数，最多两位小数');
        }
        if ($amount < 1) {
            $this->invalid('money', '退款金额必须大于零');
        }
        return ['amount' => $amount, 'request_no' => $input['refund_no'] ?? null];
    }

    /** 协议约定字节上限，不能用框架 max 的字符数规则替代；string 规则在此前执行。 */
    protected function byteLength(string $value, int $maximum): bool
    {
        return strlen($value) <= $maximum;
    }

    /** @param array<string|int,string> $input 每次克隆隔离场景/追加规则，防止常驻容器状态串用。 */
    private function assertScene(string $scene, array $input): void
    {
        $validator = clone $this;
        if (!$validator->scene($scene)->batch(false)->failException(false)->check($input)) {
            $errors = $validator->getError(true);
            $field = array_key_first($errors);
            $this->invalid($field, $errors[$field]);
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw new BusinessException($message, ApiResponseConstant::VALIDATION_FAILED, [
            $field => [$message],
        ]);
    }
}
