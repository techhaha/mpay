<?php
declare(strict_types=1);

namespace app\service\system\runtime;

use app\common\util\SafeLogger;
use app\repository\system\RuntimeDiagnosticRepository;
use app\service\system\install\MigrationService;
use app\service\system\config\SystemConfigService;
use think\App;

/** 只读检查当前 PHP 入口环境与持久运行状态；不安装依赖、改配置、修复迁移或宣称真实平台已验收。 */
final class SystemDoctorService
{
    public function __construct(private readonly App $app, private readonly SafeLogger $logger, private readonly RuntimeDiagnosticRepository $diagnostics,
        private readonly RuntimeStatusService $runtime)
    {
    }

    /**
     * 单项失败仍继续其他独立检查；公开输出只含固定诊断和非敏感版本/计数，异常细节走安全日志。
     *
     * @return list<array{id:string,status:string,message:string}> status 为 ok/warning/error，不用页面打开代替完整验收。
     */
    public function inspect(): array
    {
        $checks = [];
        $checks[] = $this->check('php', function (): array {
            $required = ['pdo', 'pdo_mysql', 'openssl', 'curl', 'fileinfo', 'gd'];
            $missing = array_values(array_filter($required, static fn (string $extension): bool => !extension_loaded($extension)));
            $ok = PHP_VERSION_ID >= 80200 && $missing === []
                && version_compare(curl_version()['version'], '7.59.0', '>=') && function_exists('mb_check_encoding')
                && function_exists('imagepng') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefromwebp');
            return [$ok ? 'ok' : 'error', 'PHP ' . PHP_VERSION . '；libcurl ' . (extension_loaded('curl') ? curl_version()['version'] : '未加载')
                . '；UTF-8 工具' . (function_exists('mb_check_encoding') ? '可用' : '缺失') . '；缺失扩展：' . ($missing === [] ? '无' : implode(',', $missing))
                . '；图片 PNG/JPEG/WebP 编解码' . (function_exists('imagepng') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefromwebp') ? '可用' : '缺失')
                . '；锁文件兼容性还需按安装文档执行 Composer 平台检查'];
        });
        $checks[] = $this->check('site', function (): array {
            $production = $this->app->config->get('mpay.environment') === 'production';
            $secure = $this->app->config->get('cookie.secure') === true;
            if ($production && (!$secure || $this->app->isDebug())) {
                return ['error', '生产环境请启用 Secure Cookie 并关闭调试模式，同时为站点配置 HTTPS'];
            }
            return ['ok', '本站按访问域名生成链接；HTTPS 需按实际访问核验。业务回调允许目标由各接入应用配置。'];
        });
        $checks[] = $this->check('runtime', function (): array {
            $runtime = realpath($this->app->getRuntimePath());
            $public = realpath($this->app->getRootPath() . 'public');
            if ($runtime === false || !is_writable($runtime) || $public === false
                || str_starts_with(str_replace('\\', '/', $runtime) . '/', str_replace('\\', '/', $public) . '/')) {
                return ['error', 'runtime 不可写或处于公开目录'];
            }
            return ['ok', '运行目录可写且与 public 隔离'];
        });
        $checks[] = $this->check('database', function (): array {
            // 版本和会话变量属于受控元数据诊断，ORM 无对应表映射；不读取或改写业务行。
            $row = $this->app->db->connect()->query('SELECT VERSION() AS version,@@session.time_zone AS zone,@@session.character_set_connection AS charset,@@session.sql_mode AS sql_mode')[0];
            $ok = $row['zone'] === '+08:00' && $row['charset'] === 'utf8mb4'
                && (str_contains($row['sql_mode'], 'STRICT_ALL_TABLES') || str_contains($row['sql_mode'], 'STRICT_TRANS_TABLES'));
            return [$ok ? 'ok' : 'error', 'MySQL ' . $row['version'] . '；会话时区/字符集/严格模式' . ($ok ? '符合要求' : '不符合要求')];
        });
        $checks[] = $this->check('migrations', function (): array {
            $state = $this->app->make(MigrationService::class)->inspect($this->app->getRootPath() . 'database/migrations');
            return [$state['pending_steps'] === 0 ? 'ok' : 'warning', '已核对 ' . $state['completed_steps'] . '/' . $state['total_steps']
                . ' 步；' . ($state['pending_steps'] > 0 ? '建表步骤尚未完成，请继续安装或执行 system:migrate' : '建表进度与表存在性检查通过')];
        });
        $checks[] = $this->check('notification', function (): array {
            $summary = $this->diagnostics->notificationSummary(date('Y-m-d H:i:s'));
            $quarantined = $summary['quarantined'];
            $expired = $summary['expired_leases'];
            return [$quarantined + $expired > 0 ? 'warning' : 'ok', '异常通知 ' . $quarantined . ' 条；等待恢复的通知 ' . $expired . ' 条'];
        });
        $checks[] = $this->check('collection', function (): array {
            $raw = $this->diagnostics->collectionStates();
            $allowed = \app\common\constant\ReceiptRuntimeConstant::LABELS;
            $states = array_intersect_key($raw, $allowed);
            if (array_diff_key($raw, $allowed) !== []) {
                $states['invalid'] = array_sum(array_diff_key($raw, $allowed));
            }
            $attention = array_diff(array_keys($states), [\app\common\constant\ReceiptRuntimeConstant::HEALTHY, \app\common\constant\ReceiptRuntimeConstant::IDLE, \app\common\constant\ReceiptRuntimeConstant::PUSH]);
            $labels = [];
            foreach ($states as $status => $count) { $labels[] = ($allowed[$status] ?? '未知状态') . ' ' . $count . ' 个'; }
            return [$states === [] || $attention !== [] ? 'warning' : 'ok',
                $states === [] ? '尚无账号运行态，不能判断平台可用' : '账号采集状态：' . implode('；', $labels)];
        });
        $checks[] = $this->check('workers', fn (): array => $this->workers());
        return $checks;
    }

    /**
     * 心跳仅代表进程最近报告过活动，不把这个检查等同于 PID 仍在运行或平台在线。
     * 共享锁与 CliWorkerControl 写锁配合，跳过未写完或超出固定格式的文件。
     *
     * @return array{string,string}
     */
    private function workers(): array
    {
        $snapshot = $this->runtime->workers();
        $missing = [];
        foreach ($snapshot['workers'] as $worker) {
            if (!$worker['recent']) { $missing[] = $worker['role']; }
        }
        return [$missing === [] ? 'ok' : 'warning', $missing === [] ? '两个工作入口有近期心跳；不代表平台在线'
            : '缺少近期工作者心跳：' . implode(',', $missing)];
    }

    /** @param callable():array{string,string} $operation 单项只读检查，不允许在失败后自动执行修复。 */
    private function check(string $id, callable $operation): array
    {
        try {
            [$status, $message] = $operation();
            return ['id' => $id, 'status' => $status, 'message' => $message];
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            return ['id' => $id, 'status' => 'error', 'message' => '检查未完成，请核对本项配置及脱敏技术日志'];
        }
    }
}
