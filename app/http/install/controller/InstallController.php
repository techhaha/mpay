<?php
declare(strict_types=1);

namespace app\http\install\controller;

use app\common\base\BaseController;
use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\http\install\validation\InstallValidator;
use app\service\system\access\AdminSessionService;
use app\service\system\install\WebInstallService;
use think\Response;
use think\Session;

/** 安装页面及同源 JSON 入口；模板不接收数据库密码，核心建表/初始化交给服务。 */
final class InstallController extends BaseController
{
    public function index(AdminSessionService $sessions): Response
    {
        return view('install/index', [
            'title' => '安装 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
        ]);
    }

    public function environment(WebInstallService $service): Response
    {
        return $this->success($service->environment());
    }

    public function check(InstallValidator $validator, WebInstallService $service): Response
    {
        return $this->success($service->check($validator->database($this->body())));
    }

    public function install(
        InstallValidator $validator,
        WebInstallService $service,
        Session $session,
    ): Response {
        // 浏览器超时只停止等待，不能回滚 MySQL DDL；重入由迁移账本与安装目标检查负责。
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $input = $validator->installation($this->body());
        if ($input['overwrite']) {
            // 文件会话清理后当前请求仍会保存内存数据；先移除管理员，保留安装表单的 CSRF。
            $session->delete('admin');
        }
        return $this->success($service->install($input));
    }

    /** @return array<string,mixed> 正文大小与同源/CSRF 已由路由中间件限制，嵌套对象留给验证器。 */
    private function body(): array
    {
        try {
            $value = json_decode($this->request->getInput(), false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BusinessException(
                '安装正文必须为有效 JSON',
                ApiResponseConstant::BAD_REQUEST,
            );
        }
        if (!($value instanceof \stdClass)) {
            throw new BusinessException(
                '安装正文必须为 JSON 对象',
                ApiResponseConstant::BAD_REQUEST,
            );
        }
        return get_object_vars($value);
    }
}
