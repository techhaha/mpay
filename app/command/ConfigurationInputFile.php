<?php
declare(strict_types=1);

namespace app\command;

use app\common\util\PrivateFilePath;

/** 管理命令从本机私有 JSON 读取完整输入，避免把平台凭证放进命令行参数及进程列表。 */
final class ConfigurationInputFile
{
    public function __construct(private readonly PrivateFilePath $paths)
    {
    }

    /**
     * 仅 CLI、有限文件大小、根对象且位于 public 之外；Unix 拒绝组/其他用户可读写的权限。
     * Windows 仍由部署账号的 ACL 保护，不能用 Unix mode 检查冒充已经核验 Windows ACL。
     *
     * @return array<string,mixed> 敏感输入，调用者必须只输出服务返回的公开引用。
     * @throws \InvalidArgumentException 只返回固定错误，不输出文件内容、解析片段或绝对路径。
     */
    public function read(string $path): array
    {
        if (PHP_SAPI !== 'cli') { throw new \LogicException('管理输入文件仅供本机 CLI 使用'); }
        $resolved = $this->paths->existing($path);
        $permissions = fileperms($resolved);
        if (DIRECTORY_SEPARATOR !== '\\' && ($permissions === false || ($permissions & 0077) !== 0)) {
            throw new \InvalidArgumentException('管理输入文件只能由当前部署账号读写（例如 0600）');
        }
        $size = filesize($resolved);
        if ($size === false || $size < 2 || $size > 1048576) { throw new \InvalidArgumentException('管理输入文件大小无效'); }
        $json = file_get_contents($resolved, false, null, 0, 1048577);
        if ($json === false || strlen($json) > 1048576) { throw new \InvalidArgumentException('管理输入文件无法完整读取'); }
        try {
            $root = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            if (!$root instanceof \stdClass) { throw new \InvalidArgumentException('管理输入根节点必须为 JSON 对象'); }
            // 保留内层对象/列表差异，防止 settings: [] 在关联数组解码后冒充 settings: {}。
            return get_object_vars($root);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('管理输入不是有效 JSON 对象');
        }
    }
}
