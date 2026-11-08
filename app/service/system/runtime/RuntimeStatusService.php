<?php
declare(strict_types=1);

namespace app\service\system\runtime;

use RuntimeException;
use think\App;
use think\Cache;

/** 工作者及已认证客户端的运行快照；心跳不证明支付应用登录或实际收款能力。 */
final class RuntimeStatusService
{
    // 客户端约 60 秒上报，允许三个周期；仅用于运行展示，不控制通道或订单。
    public const LISTENER_TIMEOUT_SECONDS = 180;

    public function __construct(private readonly App $app, private readonly Cache $cache) {}

    /** 每通道只覆盖最新快照，使用服务端接收时间；不写数据库或逐条回调日志。 */
    public function recordListener(
        string $channelId,
        string $configurationId,
        int $configurationVersion,
        int $receivedAt,
        array $heartbeat,
    ): void {
        $saved = $this->cache->store('receipt')->set('listener.heartbeat.' . $channelId, [
            'configuration_id' => $configurationId,
            'config_version' => $configurationVersion,
            'received_at' => $receivedAt,
            'listening' => $heartbeat['listening'],
            'package_name' => $heartbeat['package_name'],
            'device_name' => $heartbeat['device_name'],
            'client' => $heartbeat['client'],
        ], 0);
        if (!$saved) {
            throw new RuntimeException('监听状态保存失败');
        }
    }

    /** 当前配置以外的快照不当作当前状态，缓存缺失表示尚无上报。 */
    public function listener(string $channelId, string $configurationId, int $configurationVersion): ?array
    {
        $state = $this->cache->store('receipt')->get('listener.heartbeat.' . $channelId);
        return is_array($state)
            && ($state['configuration_id'] ?? null) === $configurationId
            && ($state['config_version'] ?? null) === $configurationVersion
            && is_int($state['received_at'] ?? null)
            && is_bool($state['listening'] ?? null)
            ? $state : null;
    }

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
