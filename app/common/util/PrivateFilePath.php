<?php
declare(strict_types=1);

namespace app\common\util;

use think\App;

/** CLI 敏感输入使用的本地路径边界；同时检查访问路径和真实目标，不创建目录或改变权限。 */
final class PrivateFilePath
{
    public function __construct(private readonly App $app) {}

    /**
     * 相对路径固定从项目根目录解析，不随 FPM/CLI 的工作目录变化。
     *
     * @return string 已存在、可读且位于 public 之外的真实文件路径；调用者仍须核对权限与内容。
     * @throws \InvalidArgumentException 固定错误不含配置路径或文件正文。
     */
    public function existing(string $path): string
    {
        $absolute = $this->absolute($path);
        $resolved = realpath($absolute);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \InvalidArgumentException('私有文件不存在或不可读');
        }
        $this->assertPrivate($absolute, $resolved);
        return $resolved;
    }

    /**
     * 新文件的父目录必须已存在；返回真实父目录下的目标路径，不自行创建文件。
     * 调用者必须继续用独占创建，不能把此检查当作已排除并发覆盖。
     *
     * @throws \InvalidArgumentException 非本地路径、公开路径、无效父目录或目标已存在。
     */
    public function forCreation(string $path): string
    {
        $absolute = $this->absolute($path);
        $name = basename($absolute);
        $parent = realpath(dirname($absolute));
        if ($parent === false || !is_dir($parent) || $name === '' || in_array($name, ['.', '..'], true)
            || (DIRECTORY_SEPARATOR === '\\' && preg_match('/[. ]$/D', $name))) {
            throw new \InvalidArgumentException('私有文件名无效或父目录不存在');
        }
        $resolved = $parent . DIRECTORY_SEPARATOR . $name;
        $this->assertPrivate($absolute, $resolved);
        // is_link 同时拒绝悬空链接；fopen 的 x 模式仍负责最终的排他创建。
        if (file_exists($resolved) || is_link($resolved)) { throw new \InvalidArgumentException('私有文件已存在，拒绝覆盖'); }
        return $resolved;
    }

    private function absolute(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://~', $path)) {
            throw new \InvalidArgumentException('私有文件需要本地文件路径');
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = str_replace('\\', '/', $path);
            // 不接收 UNC、驱动器相对路径或 NTFS 数据流；避免多种路径别名绕过 public 边界。
            if (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:(?!/)~', $path)
                || str_contains(preg_replace('~^[A-Za-z]:/~', '', $path), ':')) {
                throw new \InvalidArgumentException('Windows 私有文件需要完整驱动器路径或普通相对路径');
            }
        }
        return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) ? $path : $this->app->getRootPath() . $path;
    }

    /** 检查 public 本身的访问路径和真实目录，也拒绝 public 内链接到外部私有文件的访问路径。 */
    private function assertPrivate(string $configured, string $resolved): void
    {
        $public = $this->app->getRootPath() . 'public';
        $actualPublic = realpath($public);
        if ($actualPublic === false || !is_dir($actualPublic)) { throw new \InvalidArgumentException('无法核对站点公开目录'); }
        foreach ([$public, $actualPublic] as $root) {
            $root = $this->comparisonPath($root);
            foreach ([$configured, $resolved] as $path) {
                $normal = $this->comparisonPath($path);
                if ($normal === $root || str_starts_with($normal, $root . '/')) {
                    throw new \InvalidArgumentException('私有文件的访问路径和实际位置都必须在 public 之外');
                }
            }
        }
    }

    /** 只做边界比较，不用此字符串访问文件；真实文件访问仍依赖 realpath，避免自行解释符号链接。 */
    private function comparisonPath(string $path): string
    {
        $path = str_replace('\\', '/', $path); $segments = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') {
                if ($segments !== [] && !str_ends_with($segments[array_key_last($segments)], ':')) { array_pop($segments); }
            } else { $segments[] = $part; }
        }
        $normal = (str_starts_with($path, '/') ? '/' : '') . implode('/', $segments);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normal) : $normal;
    }
}
