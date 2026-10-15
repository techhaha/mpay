<?php
declare(strict_types=1);

namespace app\http\page;

use app\common\base\BaseController;
use app\service\system\config\SystemConfigService;
use app\service\system\install\WebInstallService;
use think\Config;
use think\Response;

/** 公开首页只提供项目介绍；先判断安装状态，再读取固定页面设置，不访问交易或管理员会话。 */
final class HomeController extends BaseController
{
    public function index(WebInstallService $installation, SystemConfigService $settings, Config $config): Response
    {
        if ($installation->needsSetup()) { return redirect('/install', 303); }
        if ($settings->values('page_display')['home_enabled'] !== 1) { return redirect('/admin', 303); }

        // 明确选取公开字段，作者联系方式和定制资料只由后台关于页使用。
        return view('home/index', [
            'title' => $config->get('project.name') . ' · MPAY',
            'csrf_token' => '',
            'project_name' => $config->get('project.name'),
            'tagline' => $config->get('project.tagline'),
            'description' => $config->get('project.description'),
            'links' => $config->get('project.links'),
        ])->header(['Cache-Control' => 'no-store']);
    }
}
