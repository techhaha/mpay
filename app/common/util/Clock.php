<?php
declare(strict_types=1);

namespace app\common\util;

use DateTimeImmutable;

/**
 * 框架初始化后使用应用默认时区的服务端时钟；隔离测试可替换此实例。
 * 获取锁或外部调用完成后，需要按当前时间判断的操作必须重新读取。
 */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
