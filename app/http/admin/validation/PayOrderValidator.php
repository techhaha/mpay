<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\common\constant\NotifyConstant;
use app\common\util\Money;
use app\common\validation\PaymentTypeCode;
use app\exception\BusinessException;
use app\service\payment\order\ManualReceiptEvidence;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use think\Validate;

/** 后台订单的原生字段/场景验证；金额只接收十进制元字符串，身份和操作权限不从正文获取。 */
final class PayOrderValidator extends Validate
{
    protected $rule = [
        'start_date' => ['require', 'string', 'regex' => '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D'],
        'end_date' => ['require', 'string', 'regex' => '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D'],
        'app_id' => ['string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'pid' => ['string', 'regex' => '/^[1-9][0-9]{0,31}$/D'],
        'poll_group_id' => ['string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'channel_id' => ['string', 'regex' => '/^[1-9][0-9]{0,19}$/D'],
        'pay_type' => ['string', 'regex' => PaymentTypeCode::PATTERN],
        'status' => [
            'string',
            'regex' => '/^(?:[0-5]|refund_(?:partial|full|processing|unknown|failed))$/D',
        ],
        'notification_status' => [
            'string',
            'regex' => '/^(?:none|acknowledged|[0-9])$/D',
            'in' => [
                'none',
                'acknowledged',
                NotifyConstant::STATUS_PENDING,
                NotifyConstant::STATUS_SENDING,
                NotifyConstant::STATUS_SUCCESS,
                NotifyConstant::STATUS_EXHAUSTED,
                NotifyConstant::STATUS_HELD,
            ],
        ],
        'biz_no' => ['string', 'regex' => '/^B[A-Za-z0-9]{1,63}$/D'],
        'pay_no' => ['string', 'regex' => '/^P[A-Za-z0-9]{1,63}$/D'],
        'merchant_order_no' => ['string', 'max' => 64],
        'kind' => ['require', 'string', 'in' => ['operations', 'callbacks', 'notifications']],
        'reason' => ['require', 'string', 'max' => 512, 'nonBlank'],
        'received_amount' => [
            'require',
            'string',
            'regex' => '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D',
        ],
        'paid_at' => [
            'require',
            'string',
            'regex' => '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
            'dateFormat' => 'Y-m-d H:i:s',
        ],
        'reference' => ['require', 'string', 'max' => 1024, 'nonBlank'],
        'platform_trade_no' => ['string', 'max' => 128],
        'current_password' => ['require', 'string', 'regex' => '/^[^\x00]{1,72}$/D'],
        'refund_amount' => [
            'require',
            'string',
            'regex' => '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D',
        ],
        'request_no' => ['require', 'string', 'regex' => '/^[a-f0-9]{32}$/D'],
        'notify_merchant' => ['require', 'booleanJson'],
        'expected_attempts' => [
            'require',
            'counter',
            'regex' => '/^(?:0|[1-9][0-9]{0,9})$/D',
            'between' => [0, 4294967294],
        ],
    ];
    protected $scene = [
        'filters' => [
            'start_date',
            'end_date',
            'app_id',
            'pid',
            'poll_group_id',
            'channel_id',
            'pay_type',
            'status',
            'notification_status',
            'biz_no',
            'pay_no',
            'merchant_order_no',
        ],
        'identity' => ['biz_no'],
        'history' => ['biz_no', 'kind'],
        'close' => ['biz_no', 'reason'],
        'confirm' => [
            'biz_no',
            'reason',
            'received_amount',
            'paid_at',
            'reference',
            'platform_trade_no',
            'current_password',
            'notify_merchant',
        ],
        'retry' => ['biz_no', 'reason', 'expected_attempts'],
        'refund' => ['biz_no', 'reason', 'refund_amount', 'request_no', 'current_password'],
    ];

    public function __construct(
        private readonly DateRangeValidator $dates,
        private readonly PaginationValidator $pagination,
    ) {
        parent::__construct();
    }

    /**
     * 默认最近七天；列表最多一年，导出最多 31 天，截止日转换为次日零时的排他上界。
     * notification_status 的 none 表示没有付款通知任务；0～4 表示当前调度状态，acknowledged 表示曾确认成功，空值表示不限定。
     *
     * @param array<string,mixed> $input
     * @return array{filters:array{from:string,until:string,status:int|string|null,notification_status?:?string,biz_no:?string,pay_no:?string,merchant_order_no:?string},page:int,limit:int}
     */
    public function filters(array $input, bool $export = false): array
    {
        $this->known($input, [...$this->scene['filters'], 'page', 'limit']);
        $values = array_diff_key($input, array_flip(['page', 'limit']));
        foreach ($values as $key => $value) {
            if ($value === '') {
                unset($values[$key]);
            }
        }
        $range = $this->dates->range($values, $export ? 31 : 366);
        $values += ['start_date' => $range['start_date'], 'end_date' => $range['end_date']];
        $this->checkInput('filters', $values);
        return [
            'filters' => [
                'from' => $range['from'],
                'until' => $range['until'],
                'status' => isset($values['status'])
                    ? (ctype_digit($values['status'])
                        ? (int) $values['status']
                        : $values['status'])
                    : null,
                'biz_no' => $values['biz_no'] ?? null,
                'notification_status' => $values['notification_status'] ?? null,
                'app_id' => $values['app_id'] ?? null,
                'pid' => $values['pid'] ?? null,
                'poll_group_id' => $values['poll_group_id'] ?? null,
                'channel_id' => $values['channel_id'] ?? null,
                'pay_type' => $values['pay_type'] ?? null,
                'pay_no' => $values['pay_no'] ?? null,
                'merchant_order_no' => $values['merchant_order_no'] ?? null,
            ],
        ] + $this->pagination->validate($input);
    }

    /** @param array<string,mixed> $input */
    public function identity(array $input): string
    {
        $this->known($input, ['biz_no']);
        $this->checkInput('identity', $input);
        return $input['biz_no'];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{biz_no:string,kind:string,page:int,limit:int}
     */
    public function history(array $input): array
    {
        $this->known($input, ['biz_no', 'kind', 'page', 'limit']);
        $this->checkInput('history', $input);
        return ['biz_no' => $input['biz_no'], 'kind' => $input['kind']] +
            $this->pagination->validate($input);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> 固定动作字段；received_amount/paid_at 仍由 evidence 转为领域值。
     */
    public function action(string $action, array $input): array
    {
        if (!in_array($action, ['confirm', 'close', 'retry', 'refund'], true)) {
            throw new LogicException('未知订单操作场景');
        }
        $this->known($input, $this->scene[$action]);
        if (
            $action === 'refund' &&
            (!isset($input['reason']) ||
                (is_string($input['reason']) && trim($input['reason']) === ''))
        ) {
            $input['reason'] = '管理员申请退款';
        }
        foreach (['platform_trade_no'] as $optional) {
            if (($input[$optional] ?? null) === '') {
                unset($input[$optional]);
            }
        }
        $this->checkInput($action, $input);
        if ($action === 'retry') {
            $input['expected_attempts'] = (int) $input['expected_attempts'];
        } elseif ($action === 'refund') {
            try {
                $input['refund_amount'] = Money::parse($input['refund_amount']);
            } catch (InvalidArgumentException) {
                throw new BusinessException(
                    '退款金额必须为有效的正数元金额',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
            if ($input['refund_amount'] < 1) {
                throw new BusinessException(
                    '退款金额必须大于零',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
        }
        return $input;
    }

    /** 通知选择必须是 JSON 布尔值，不能把字符串 false 当成真值。 */
    protected function booleanJson(mixed $value): bool
    {
        return is_bool($value);
    }

    /** @param array<string,mixed> $input confirm 场景已通过，构造真实金额/时间/引用，绝不自动填期望金额作为到账证据。 */
    public function evidence(array $input): ManualReceiptEvidence
    {
        try {
            return new ManualReceiptEvidence(
                Money::parse($input['received_amount']),
                new DateTimeImmutable($input['paid_at']),
                $input['platform_trade_no'] ?? null,
                $input['reference'],
            );
        } catch (InvalidArgumentException) {
            throw new BusinessException(
                '收款证据的金额、时间或引用格式无效',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
    }

    /** @param array<string,mixed> $input */
    private function checkInput(string $scene, array $input): void
    {
        $validator = (clone $this)->scene($scene)->batch()->failException(false);
        if ($scene !== 'filters') {
            $validator->append('biz_no', 'require');
        }
        foreach (array_keys(array_intersect_key($input, $this->rule)) as $field) {
            $validator->append($field, 'require');
        }
        if (!$validator->check($input)) {
            // 不使用含动态规则值的默认文案，更不把密码或原始证据拼到响应中。
            $errors = [];
            foreach (array_keys($validator->getError(true)) as $field) {
                $errors[$field] = ['字段格式或范围无效'];
            }
            throw new BusinessException(
                '请检查筛选或操作字段',
                ApiResponseConstant::VALIDATION_FAILED,
                $errors,
            );
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $allowed
     */
    private function known(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new BusinessException('包含未允许的字段', ApiResponseConstant::VALIDATION_FAILED);
        }
    }

    protected function nonBlank(mixed $value): bool
    {
        return is_string($value) &&
            trim($value) !== '' &&
            mb_check_encoding($value, 'UTF-8') &&
            !str_contains($value, "\0");
    }

    protected function counter(mixed $value): bool
    {
        return is_int($value) || is_string($value);
    }
}
