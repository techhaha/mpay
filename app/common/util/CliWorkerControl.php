<?php
declare(strict_types=1);

namespace app\common\util;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/** CLI 进程的安全退出、短等待和进程心跳；心跳只证明本地工作者运行，不证明任何设备在线。 */
final class CliWorkerControl
{
    /** @var resource|null 同一运行目录内的角色锁，由操作系统在进程退出时释放。 */
    private $roleLock = null;
    private bool $stopping = false;
    private bool $signalsInstalled = false;
    private bool $previousAsync = false;
    /** @var array<int,callable|int> 被临时替换的信号处理器，退出时恢复。 */
    private array $previousHandlers = [];

    /**
     * 信号处理是可选增强；不依赖 fork/posix/exec，也不要求为常驻启动解除禁用函数。
     * 返回 false 时由外部守护停止进程；通知依靠持久任务恢复，采集重新读取待核对订单。
     */
    public function install(): bool
    {
        foreach (['pcntl_async_signals', 'pcntl_signal_get_handler', 'pcntl_signal'] as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }
        if (!defined('SIGTERM') || !defined('SIGINT')) {
            return false;
        }
        $this->previousAsync = pcntl_async_signals();
        foreach ([SIGTERM, SIGINT] as $signal) {
            $this->previousHandlers[$signal] = pcntl_signal_get_handler($signal);
            // 只设置退出意图；不中断正在提交的事务/结果回写，下一轮不再认领。
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }
        pcntl_async_signals(true);
        $this->signalsInstalled = true;
        return true;
    }

    /** 同一运行目录每个角色只启动一个进程；watch 不支持跨主机多实例登录同一账号。 */
    public function acquire(string $runtimePath, string $role): void
    {
        if (!in_array($role, ['work', 'watch'], true) || $this->roleLock !== null) {
            throw new LogicException('工作者角色无效或重复获取锁');
        }
        $directory = rtrim($runtimePath, '/\\') . '/workers';
        if (
            is_link($directory) ||
            (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
        ) {
            throw new RuntimeException('工作者目录无效');
        }
        $path = $directory . '/' . $role . '.lock';
        if (is_link($path)) {
            throw new RuntimeException('工作者锁不能是符号链接');
        }
        $stream = fopen($path, 'c+b');
        if ($stream === false) {
            throw new RuntimeException('无法打开工作者锁');
        }
        if (!flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);
            throw new RuntimeException('该角色已有工作者运行');
        }
        $this->roleLock = $stream;
    }

    public function stopping(): bool
    {
        return $this->stopping;
    }

    /** 空闲等待每 100ms 观察退出意图，收到 TERM/INT 后不等完整轮询间隔。 */
    public function idle(): void
    {
        for ($step = 0; $step < 10 && !$this->stopping; $step++) {
            usleep(100000);
        }
    }

    /**
     * 每进程独立文件，避免多个工作者互相覆盖；读取端需使用共享文件锁。
     * runtime 中的非敏感运行证据，不进入源码或支付事实，可供 doctor/守护排障。
     */
    public function heartbeat(string $runtimePath, string $role, string $owner, bool $running): void
    {
        if (!in_array($role, ['work', 'watch'], true) || !preg_match('/^[a-f0-9]{32}$/D', $owner)) {
            throw new InvalidArgumentException('工作者心跳身份无效');
        }
        $directory = rtrim($runtimePath, '/\\') . '/workers';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建工作者心跳目录');
        }
        $path = $directory . '/' . $role . '-' . $owner . '.json';
        $stream = fopen($path, 'c+b');
        if ($stream === false) {
            throw new RuntimeException('无法写入工作者心跳');
        }
        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException('无法锁定工作者心跳');
            }
            $json = json_encode(
                [
                    'role' => $role,
                    'owner' => $owner,
                    'pid' => getmypid(),
                    'running' => $running,
                    'observed_at' => time(),
                ],
                JSON_THROW_ON_ERROR,
            );
            if (
                !ftruncate($stream, 0) ||
                fwrite($stream, $json) !== strlen($json) ||
                !fflush($stream)
            ) {
                throw new RuntimeException('工作者心跳未完整写入');
            }
        } finally {
            fclose($stream);
        }
    }

    /** 仅恢复本实例安装的处理器，方便同进程测试或控制台继续执行其他命令。 */
    public function restore(): void
    {
        if (is_resource($this->roleLock)) {
            fclose($this->roleLock);
            $this->roleLock = null;
        }
        if ($this->signalsInstalled) {
            foreach ($this->previousHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($this->previousAsync);
            $this->signalsInstalled = false;
        }
    }
}
