<?php
declare(strict_types=1);

namespace app\http\page;

use app\common\base\BaseAdminController;
use app\common\util\Clock;
use app\service\system\access\AdminAuthService;
use app\service\system\access\AdminSessionService;
use app\service\system\config\SystemConfigService;
use app\service\system\install\WebInstallService;
use think\Config;
use think\exception\HttpException;
use think\Response;

/** 后台模板入口，只渲染公开身份、表单 token 和日期默认值；统计、列表和管理动作由受保护同源 API 完成。 */
final class AdminController extends BaseAdminController
{
    /** 后台独立入口不受公开首页开关影响；认证由目标页面负责，首次无配置时进入安装。 */
    public function index(WebInstallService $installation): Response
    {
        return redirect($installation->needsSetup() ? '/install' : '/admin/dashboard', 303);
    }

    /** 已登录时跳到固定工作台；失效旧身份先撤销，不把旧 token 继续交给登录表单。 */
    public function login(
        AdminSessionService $sessions,
        AdminAuthService $auth,
        WebInstallService $installation,
    ): Response {
        if ($installation->needsSetup()) {
            return redirect('/install', 303);
        }
        $stored = $sessions->identity();
        if ($stored !== null) {
            if ($auth->current($stored['id'], $stored['session_version']) !== null) {
                return redirect('/admin/dashboard', 303);
            }
            $sessions->invalidate();
        }
        return view('admin/login', [
            'title' => '登录 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
        ]);
    }

    /** 路由已挂认证；不在模板中查库或从请求参数拼接账户信息。 */
    public function account(AdminSessionService $sessions): Response
    {
        $identity = $this->identity();
        return $this->pageView('admin/account', [
            'title' => '账户安全 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $identity['username'],
        ]);
    }

    /** 仅提供页面和服务端日期默认值，订单事实由受保护同源 API 读取。 */
    public function orders(AdminSessionService $sessions, Clock $clock): Response
    {
        $today = $clock->now();
        return $this->pageView('admin/orders', [
            'title' => '交易订单 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
            'start_date' => $today->modify('-6 days')->format('Y-m-d'),
            'end_date' => $today->format('Y-m-d'),
        ]);
    }

    public function dashboard(AdminSessionService $sessions, Clock $clock): Response
    {
        return $this->pageView('admin/dashboard', [
            'title' => '工作台 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
            'date' => $clock->now()->format('Y-m-d'),
        ]);
    }

    public function receipts(AdminSessionService $sessions, Clock $clock): Response
    {
        return $this->records('receipts', '到账流水', $sessions, $clock);
    }

    public function notifications(AdminSessionService $sessions, Clock $clock): Response
    {
        return $this->records('notifications', '商户通知', $sessions, $clock);
    }

    public function ingress(AdminSessionService $sessions, Clock $clock): Response
    {
        return $this->records('ingress', '通道回调日志', $sessions, $clock);
    }

    public function audit(AdminSessionService $sessions, Clock $clock): Response
    {
        return $this->records('audit', '操作日志', $sessions, $clock);
    }

    /** 配置正文由认证 API 按版本提供；模板仅输出身份和 CSRF，不把平台秘密嵌入 HTML。 */
    public function configurations(AdminSessionService $sessions): Response
    {
        return $this->pageView('admin/configurations', [
            'title' => '收款配置 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
        ]);
    }

    /** 插件页只提供身份与 CSRF；目录和开关均由认证接口读取。 */
    public function plugins(AdminSessionService $sessions): Response
    {
        return $this->pageView('admin/plugins', [
            'title' => '插件管理 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
        ]);
    }

    /** 系统设置页仅输出身份与 CSRF，当前值和版本由同源 API 提供。 */
    public function settings(AdminSessionService $sessions): Response
    {
        return $this->pageView('admin/settings', [
            'title' => '系统设置 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
        ]);
    }

    /** 作者资料只在已认证且已开启的关于页中渲染，不通过公共首页或匿名 API 返回。 */
    public function about(AdminSessionService $sessions, Config $config): Response
    {
        return $this->pageView('admin/about', [
            'title' => '关于 MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
            'project' => $config->get('project'),
        ]);
    }

    /** 接入页模板不读取或内嵌 KEY；秘密仅通过后续有密码复核的专用 POST 获取。 */
    public function applications(AdminSessionService $sessions): Response
    {
        return $this->pageView('admin/applications', [
            'title' => '应用管理 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
        ]);
    }

    /** 组策略与应用绑定只通过后台 API 编辑，模板不自行选路。 */
    public function pollGroups(AdminSessionService $sessions): Response
    {
        return $this->pageView('admin/poll-groups', [
            'title' => '轮询组 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
        ]);
    }

    /** 运行维护页面只输出布局，状态和按需只读诊断走认证 API；模板不执行任何维护命令。 */
    public function runtime(AdminSessionService $sessions): Response
    {
        return $this->pageView('admin/runtime', [
            'title' => '运行维护 · MPAY',
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
        ]);
    }

    /** 四个只读列表共用布局；kind 只由上述固定路由方法给出，不作为任意模板路径或服务类名。 */
    private function records(
        string $kind,
        string $title,
        AdminSessionService $sessions,
        Clock $clock,
    ): Response {
        $today = $clock->now();
        return $this->pageView('admin/records', [
            'title' => $title . ' · MPAY',
            'heading' => $title,
            'kind' => $kind,
            'csrf_token' => $sessions->csrfToken(),
            'username' => $this->identity()['username'],
            'start_date' => $today->modify('-6 days')->format('Y-m-d'),
            'end_date' => $today->format('Y-m-d'),
        ]);
    }

    /**
     * 后台模板共用一次页面配置读取，菜单和关于页准入使用同一结果；模板不执行查询。
     * @param array<string,mixed> $data 已按具体页面筛选的模板数据。
     */
    private function pageView(string $template, array $data): Response
    {
        $display = $this->app->make(SystemConfigService::class)->values('page_display');
        if ($template === 'admin/about' && $display['about_enabled'] !== 1) {
            throw new HttpException(404);
        }
        return view($template, $data + ['about_enabled' => $display['about_enabled']]);
    }
}
