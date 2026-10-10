<?php
declare(strict_types=1);

namespace app\service\system\runtime;

use app\common\util\Clock;
use think\App;

/** 清理应用运行目录内的旧技术日志和已退出进程心跳；交易、流水、通知、审计和密钥不在此范围。 */
final class RuntimePruneService
{
    public function __construct(private readonly App $app, private readonly Clock $clock)
    {
    }

    /**
     * 默认仅预览；执行时再次核对范围、类型、修改时间和心跳状态，不递归删除目录。
     * 文件操作不能事务回滚；中途失败返回异常，已完成的非关键文件清理不会被声称撤回。
     *
     * @param bool $execute 由 CLI 的显式 --execute 或常驻例行清理开启，不接受 Web 请求直接调用。
     * @param int $limit 单次最多候选数，按最旧修改时间优先；已退出心跳保持 7 天。
     * @param int $budgetMilliseconds 0 不设扫描期限；常驻入口使用小预算，耗尽明确返回不完整。
     * @param int $logDays 技术日志保留天数，由 CLI 读取已保存设置或显式给出，服务本身不连接数据库。
     * @return array{dry_run:bool,candidates:int,removed:int,skipped:int,files:list<array{file:string,bytes:int}>,scan_complete:bool,budget_exhausted:bool}
     */
    public function run(bool $execute = false, int $limit = 500, int $logDays = 30, int $budgetMilliseconds = 0): array
    {
        if ($limit < 1 || $limit > 5000) {
            throw new \InvalidArgumentException('清理批次必须为 1～5000');
        }
        if ($logDays < 7 || $logDays > 365) { throw new \InvalidArgumentException('技术日志保留期必须为 7～365 天'); }
        $configured = rtrim($this->app->getRuntimePath(), '/\\');
        $root = realpath($configured);
        $public = realpath($this->app->getRootPath() . 'public');
        if ($root === false || is_link($configured) || $public === false || $this->within($root, $public)) {
            throw new \RuntimeException('清理需要明确且非公开的真实 runtime 目录');
        }
        if ($budgetMilliseconds < 0 || $budgetMilliseconds > 60000) { throw new \InvalidArgumentException('清理时间预算无效'); }
        $started = hrtime(true);
        $elapsed = static fn (): float => (hrtime(true) - $started) / 1000000;
        $scanComplete = true;
        $candidates = [];
        $oldestFirst = static fn (array $a, array $b): int => [$a['mtime'], $a['path']] <=> [$b['mtime'], $b['path']];
        foreach (['log', 'workers'] as $kind) {
            $directory = $root . DIRECTORY_SEPARATOR . $kind;
            if (!is_dir($directory) || is_link($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !$file->isLink(),
            ));
            foreach ($iterator as $file) {
                // 留一半预算给删除；自动清理不能因扫描历史日志长时间阻塞通知。
                if ($budgetMilliseconds > 0 && $elapsed() >= $budgetMilliseconds / 2) { $scanComplete = false; break 2; }
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if ($this->eligible($path, $root, $kind, $logDays)) {
                    $candidates[] = ['path' => $path, 'kind' => $kind, 'mtime' => $file->getMTime(), 'size' => $file->getSize()];
                    // 扫描大量历史时只保留足够的最旧候选，内存不随整个运行目录持续增长。
                    if (count($candidates) > $limit * 2) {
                        usort($candidates, $oldestFirst);
                        $candidates = array_slice($candidates, 0, $limit);
                    }
                }
            }
        }
        usort($candidates, $oldestFirst);
        $candidates = array_slice($candidates, 0, $limit);
        $result = ['dry_run' => !$execute, 'candidates' => count($candidates), 'removed' => 0, 'skipped' => 0, 'files' => [], 'scan_complete' => $scanComplete, 'budget_exhausted' => !$scanComplete];
        foreach ($candidates as $candidate) {
            if ($budgetMilliseconds > 0 && $elapsed() >= $budgetMilliseconds) { $result['budget_exhausted'] = true; break; }
            $path = $candidate['path'];
            $result['files'][] = ['file' => str_replace('\\', '/', substr($path, strlen($root) + 1)), 'bytes' => $candidate['size']];
            if (!$execute) {
                continue;
            }
            clearstatcache(true, $path);
            if (!$this->eligible($path, $root, $candidate['kind'], $logDays) || filemtime($path) !== $candidate['mtime'] || filesize($path) !== $candidate['size']) {
                $result['skipped']++;
                continue;
            }
            if (!unlink($path)) {
                throw new \RuntimeException('运行文件清理未完成');
            }
            $result['removed']++;
        }
        return $result;
    }

    /** 当前文件仍在对应白名单子树且满足保留门槛才可清理，符号链接和非日志文件一律跳过。 */
    private function eligible(string $path, string $root, string $kind, int $logDays): bool
    {
        $real = realpath($path);
        if ($real === false || is_link($path) || !is_file($real) || !$this->within($real, $root . DIRECTORY_SEPARATOR . $kind)) {
            return false;
        }
        $now = $this->clock->now()->getTimestamp();
        if ($kind === 'log') {
            return pathinfo($path, PATHINFO_EXTENSION) === 'log' && filemtime($path) < $now - $logDays * 86400;
        }
        if (filemtime($path) >= $now - 7 * 86400 || filesize($path) > 4096
            || !preg_match('/^(work|watch)-([a-f0-9]{32})\.json$/D', basename($path), $match)) {
            return false;
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }
        try {
            if (!flock($stream, LOCK_SH | LOCK_NB)) {
                return false;
            }
            $data = json_decode(stream_get_contents($stream, 4097), true);
        } finally {
            fclose($stream);
        }
        return is_array($data) && ($data['role'] ?? null) === $match[1] && ($data['owner'] ?? null) === $match[2]
            && ($data['running'] ?? null) === false && is_int($data['observed_at'] ?? null) && $data['observed_at'] < $now - 7 * 86400;
    }

    /** 比较带分隔符的完整边界，Windows 使用不区分大小写的规范路径，避免同前缀兄弟目录越界。 */
    private function within(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
