<?php
declare(strict_types=1);

namespace app\service\system\runtime;

use think\App;

/** 读取本机工作者心跳的有限快照；共享锁配合现有 CLI 写锁，不探测 PID、不把心跳当设备或平台在线。 */
final class RuntimeStatusService
{
    public function __construct(private readonly App $app) {}

    /**
     * 无目录、不可读、格式异常或扫描超限均不能宣称正常；不读取文件名白名单以外的内容。
     * @return array{scan_complete:bool,ignored:int,workers:list<array{role:string,label:string,observed_at:?int,recent:bool,status_label:string}>}
     */
    public function workers(): array
    {
        $latest = ['work' => 0, 'watch' => 0];
        $running = ['work' => false, 'watch' => false];
        // 与 CLI 心跳写入使用同一本机 Unix 时间，不参与交易调度。
        $now = time();
        $directory = rtrim($this->app->getRuntimePath(), '/\\') . '/workers';
        $complete = true;
        $ignored = 0;
        if (is_link($directory) || (is_dir($directory) && !is_readable($directory))) {
            $complete = false;
        } elseif (is_dir($directory)) {
            $examined = 0;
            try {
                foreach (new \DirectoryIterator($directory) as $file) {
                    if ($file->isDot()) {
                        continue;
                    }
                    if (++$examined > 2000) {
                        $complete = false;
                        break;
                    }
                    if (
                        $file->isLink() ||
                        !$file->isFile() ||
                        !preg_match(
                            '/^(work|watch)-([a-f0-9]{32})\.json$/D',
                            $file->getFilename(),
                            $match,
                        ) ||
                        $file->getSize() > 4096
                    ) {
                        $ignored++;
                        continue;
                    }
                    $stream = @fopen($file->getPathname(), 'rb');
                    if ($stream === false) {
                        $complete = false;
                        continue;
                    }
                    try {
                        if (!flock($stream, LOCK_SH | LOCK_NB)) {
                            $complete = false;
                            continue;
                        }
                        $contents = @stream_get_contents($stream, 4097);
                        if (!is_string($contents)) {
                            $complete = false;
                            continue;
                        }
                        $data = json_decode($contents, true);
                    } finally {
                        fclose($stream);
                    }
                    if (
                        !is_array($data) ||
                        ($data['role'] ?? null) !== $match[1] ||
                        ($data['owner'] ?? null) !== $match[2] ||
                        !is_int($data['observed_at'] ?? null) ||
                        $data['observed_at'] < 1 ||
                        $data['observed_at'] > $now + 5 ||
                        !is_bool($data['running'] ?? null)
                    ) {
                        $ignored++;
                        continue;
                    }
                    // 保留最近退出时间供运营核对单轮任务；退出记录不能当作存活心跳。
                    $role = $match[1];
                    if ($data['observed_at'] >= $latest[$role]) {
                        $running[$role] =
                            $data['observed_at'] === $latest[$role]
                                ? $running[$role] || $data['running']
                                : $data['running'];
                        $latest[$role] = $data['observed_at'];
                    }
                }
            } catch (\RuntimeException) {
                $complete = false;
            }
        }
        $workers = [];
        foreach (['work' => '通知与订单维护', 'watch' => '流水采集'] as $role => $label) {
            $recent =
                $complete && $running[$role] && $latest[$role] > 0 && $now - $latest[$role] <= 120;
            $workers[] = [
                'role' => $role,
                'label' => $label,
                'observed_at' => $latest[$role] ?: null,
                'recent' => $recent,
                'status_label' => !$complete
                    ? '任务活动记录读取不完整'
                    : ($recent
                        ? '近期有活动'
                        : ($latest[$role] > 0 && !$running[$role]
                            ? '任务已退出'
                            : '暂无近期活动')),
            ];
        }
        return ['scan_complete' => $complete, 'ignored' => $ignored, 'workers' => $workers];
    }
}
