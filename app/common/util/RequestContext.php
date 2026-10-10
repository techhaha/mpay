<?php
declare(strict_types=1);

namespace app\common\util;

/** 每个 Web 请求及 CLI 进程各自生成关联号，不信任客户端提供的身份。 */
final class RequestContext
{
    private string $requestId;

    public function __construct()
    {
        $this->requestId = bin2hex(random_bytes(16));
    }

    public function id(): string
    {
        return $this->requestId;
    }
}
