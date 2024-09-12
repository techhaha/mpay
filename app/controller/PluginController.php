<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\View;
use think\Request;

class PluginController extends BaseController
{
    // 插件管理页
    public function index()
    {
        return View::fetch();
    }
    /**
     * 列表
     * @param Request $request
     * @return Response
     * @throws GuzzleException
     */
    public function list(Request $request) {}

    /**
     * 安装
     * @param Request $request
     * @return Response
     * @throws GuzzleException|BusinessException
     */
    public function install(Request $request) {}

    /**
     * 卸载
     * @param Request $request
     * @return Response
     */
    public function uninstall(Request $request) {}

    /**
     * 支付
     * @param Request $request
     * @return string|Response
     * @throws GuzzleException
     */
    public function pay(Request $request) {}

    /**
     * 获取zip下载url
     * @param $name
     * @param $version
     * @return mixed
     * @throws BusinessException
     * @throws GuzzleException
     */
    protected function getDownloadUrl($name, $version) {}

    /**
     * 下载zip
     * @param $url
     * @param $file
     * @return void
     * @throws BusinessException
     * @throws GuzzleException
     */
    protected function downloadZipFile($url, $file) {}

    /**
     * 获取系统支持的解压命令
     * @param $zip_file
     * @param $extract_to
     * @return mixed|string|null
     */
    protected function getUnzipCmd($zip_file, $extract_to)
    {
        if ($cmd = $this->findCmd('unzip')) {
            $cmd = "$cmd -o -qq $zip_file -d $extract_to";
        } else if ($cmd = $this->findCmd('7z')) {
            $cmd = "$cmd x -bb0 -y $zip_file -o$extract_to";
        } else if ($cmd = $this->findCmd('7zz')) {
            $cmd = "$cmd x -bb0 -y $zip_file -o$extract_to";
        }
        return $cmd;
    }

    /**
     * 使用解压命令解压
     * @param $cmd
     * @return void
     * @throws BusinessException
     */
    protected function unzipWithCmd($cmd) {}

    /**
     * 获取已安装的插件列表
     * @return array
     */
    protected function getLocalPlugins() {}

    /**
     * 获取已安装的插件列表
     * @param Request $request
     * @return Response
     */
    public function getInstalledPlugins(Request $request) {}


    /**
     * 获取本地插件版本
     * @param $name
     * @return array|mixed|null
     */
    protected function getPluginVersion($name) {}

    /**
     * 获取webman/admin版本
     * @return string
     */
    protected function getAdminVersion() {}

    /**
     * 删除目录
     * @param $src
     * @return void
     */
    protected function rmDir($src) {}

    /**
     * 获取httpclient
     * @return Client
     */
    protected function httpClient() {}

    /**
     * 获取下载httpclient
     * @return Client
     */
    protected function downloadClient() {}

    /**
     * 查找系统命令
     * @param string $name
     * @param string|null $default
     * @param array $extraDirs
     * @return mixed|string|null
     */
    protected function findCmd(string $name, string $default = null, array $extraDirs = []) {}
}
