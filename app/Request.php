<?php
declare(strict_types=1);

namespace app;

// 应用请求对象类
/** HTTP 方法以实际请求为准，防止 _method/覆盖头使路由选择与 CSRF 判断使用不同方法。 */
class Request extends \think\Request
{
    public function method(bool $origin = false): string
    {
        return parent::method(true);
    }
}
