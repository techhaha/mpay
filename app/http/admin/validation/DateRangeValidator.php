<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\common\util\Clock;
use app\exception\BusinessException;
use DateTimeImmutable;
use think\Validate;

/** 后台列表共用上海时区的自然日范围；默认值只用于未提供日期，不把非法输入纠正为另一日。 */
final class DateRangeValidator extends Validate
{
    protected $rule = [
        'start_date' => ['require', 'string', 'regex' => '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D'],
        'end_date' => ['require', 'string', 'regex' => '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D'],
    ];

    public function __construct(private readonly Clock $clock)
    {
        parent::__construct();
    }

    /**
     * 调用入口另行拒绝未知字段，本验证器仅提取两个日期。
     * @param array<string,mixed> $input
     * @return array{from:string,until:string,start_date:string,end_date:string} from 含起点，until 不含次日零时。
     */
    public function range(array $input, int $maximumDays = 366, int $defaultDays = 7): array
    {
        if ($maximumDays < 1 || $maximumDays > 366 || $defaultDays < 1 || $defaultDays > $maximumDays) {
            throw new \InvalidArgumentException('日期范围配置无效');
        }
        $values = array_intersect_key($input, $this->rule);
        foreach ($values as $key => $value) { if ($value === '') { unset($values[$key]); } }
        $today = $this->clock->now()->setTime(0, 0);
        $values += ['start_date' => $today->modify('-' . ($defaultDays - 1) . ' days')->format('Y-m-d'), 'end_date' => $today->format('Y-m-d')];
        if (!(clone $this)->failException(false)->check($values)) {
            throw new BusinessException('日期必须使用年-月-日格式', ApiResponseConstant::VALIDATION_FAILED);
        }
        $dates = [];
        foreach ($values as $key => $value) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new BusinessException('日期无效，不能自动调整到其他日期', ApiResponseConstant::VALIDATION_FAILED);
            }
            $dates[$key] = $date;
        }
        $until = $dates['end_date']->modify('+1 day');
        $seconds = $until->getTimestamp() - $dates['start_date']->getTimestamp();
        if ($seconds < 1 || $seconds > $maximumDays * 86400) {
            throw new BusinessException('日期范围需要在 1～' . $maximumDays . ' 天内', ApiResponseConstant::VALIDATION_FAILED);
        }
        return ['from' => $dates['start_date']->format('Y-m-d H:i:s'), 'until' => $until->format('Y-m-d H:i:s')] + $values;
    }
}
