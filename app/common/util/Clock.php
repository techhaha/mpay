<?php
declare(strict_types=1);

namespace app\common\util;

/** 可由隔离测试替换的服务端时钟；外部调用完成时间必须重新读取，不能沿用发起时间。 */
class Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
    }
}
