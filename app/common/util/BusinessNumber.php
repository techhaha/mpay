<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Random\Randomizer;
use think\db\exception\PDOException;

/** 业务编号统一为前缀＋创建时间＋五位随机数；唯一性由对应数据库唯一索引保证。 */
final class BusinessNumber
{
    private const INDEXES = [
        'B' => 'uk_biz_no',
        'P' => 'uk_pay_no',
        'N' => 'uk_notify_no',
        'R' => 'uk_refund_no',
    ];
    private const MAX_ATTEMPTS = 5;

    /** 默认使用 PHP 原生安全随机源；允许隔离测试注入确定性引擎验证边界和碰撞。 */
    public function __construct(
        private readonly Randomizer $random = new Randomizer(),
    ) {}

    public function generate(string $prefix, DateTimeImmutable $createdAt): string
    {
        if (!isset(self::INDEXES[$prefix])) {
            throw new InvalidArgumentException('不支持的业务编号类型');
        }
        $date = $createdAt->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('YmdHis');
        $number =
            $prefix .
            $date .
            str_pad((string) $this->random->getInt(0, 99999), 5, '0', STR_PAD_LEFT);
        if (strlen($number) !== 20) {
            throw new InvalidArgumentException('创建时间超出编号支持范围');
        }
        return $number;
    }

    /**
     * 调用方拥有事务。回调只能插入这一条记录，不能派发网络请求或执行其他副作用。
     * 仅编号索引的重复键重试；商户订单、事件身份等重复仍交回原业务幂等分支。
     *
     * @param callable(string):mixed $insert 已完成参数校验的单条插入，成功后返回本次编号。
     * @throws BusinessException 五次编号碰撞，外层事务应回滚并公开返回可重试错误。
     */
    public function insert(string $prefix, DateTimeImmutable $createdAt, callable $insert): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $number = $this->generate($prefix, $createdAt);
            try {
                $insert($number);
                return $number;
            } catch (PDOException $exception) {
                $info = $exception->getData()['PDO Error Info'] ?? [];
                // 只识别驱动报文末尾的固定索引名，不记录报文中的重复值、SQL 或绑定参数。
                $pattern = '/for key [\'`](?:[^\'`]+\.)?' . self::INDEXES[$prefix] . '[\'`]\s*$/iD';
                if (
                    (int) ($info['Driver Error Code'] ?? 0) !== 1062 ||
                    !preg_match($pattern, (string) ($info['Driver Error Message'] ?? ''))
                ) {
                    throw $exception;
                }
            }
        }
        throw new BusinessException(
            '业务编号暂时分配失败，请重试原订单',
            ApiResponseConstant::SERVICE_UNAVAILABLE,
        );
    }
}
