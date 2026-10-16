<?php
declare(strict_types=1);

namespace app\common\base;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;

/** 后台 HTTP 入口共享的身份上下文及 JSON 对象读取；认证仍由显式路由中间件负责，基类不隐式查询数据库。 */
abstract class BaseAdminController extends BaseController
{
    /** @return array{id:string,username:string,session_version:int} 仅认证路由可调用，不读取浏览器提交的管理员字段。 */
    protected function identity(): array
    {
        $identity = $this->request->middleware('admin_identity');
        if (!is_array($identity)) {
            throw new \LogicException('后台入口缺少认证中间件');
        }
        return $identity;
    }

    /** @return array<string,mixed> 写路由已限制正文类型/大小；列表、标量与解析错误不能变成空对象。 */
    protected function jsonBody(): array
    {
        try {
            $body = json_decode($this->request->getInput(), false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BusinessException('JSON 正文格式无效', ApiResponseConstant::BAD_REQUEST);
        }
        if (!($body instanceof \stdClass)) {
            throw new BusinessException(
                '后台正文必须为 JSON 对象',
                ApiResponseConstant::BAD_REQUEST,
            );
        }
        return get_object_vars($body);
    }
}
