<?php
declare(strict_types=1);

namespace app\common\util;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\exception\HttpRequestException;
use app\exception\PaymentUncertainException;
use app\exception\ReceiptCollectionException;
use app\exception\ReceiptMessageException;
use think\db\exception\PDOException as DatabaseException;
use think\Log;

/**
 * 技术日志的结构化白名单与受限异常诊断；不保存请求体、异常原文、SQL、参数或任意上游文本。
 * 文件日志故障只作有限降级，不能代替或跳过交易流水、通知责任和管理员审计的持久化。
 */
final class SafeLogger
{
    // 采集只补充本地配置 ID/版本和固定来源编码，不记录真实账号、查询参数、会话或游标载荷。
    private const FIELDS = ['biz_no', 'pay_no', 'trace_no', 'event_key', 'notify_no', 'api_config_id', 'config_version', 'source',
        'channel_id', 'plugin_code', 'receipt_flow_id', 'notify_task_id',
        'reason_code', 'exception_class', 'file', 'line', 'http_status', 'duration_ms'];
    private const SQLSTATES = ['23000', '40001', 'HY000', '42000', '42S02', '42S22', '08001', '08003', '08004', '08006', '08S01'];
    private const DRIVER_CODES = [1040, 1044, 1045, 1049, 1054, 1062, 1064, 1146, 1205, 1213, 1292, 1366, 1406, 1451, 1452, 2002, 2006, 2013, 2055];

    public function __construct(private readonly Log $log, private readonly RequestContext $context)
    {
    }

    /**
     * 记录固定事件编码和允许的标量关联字段；未知字段/嵌套对象丢弃，允许字段仍须由调用方脱敏。
     * 不得把原始报文或秘密内容放进 pay_no、reason_code 等允许字段。
     *
     * @param array<string, scalar|null> $context 业务号、原因码或毫秒耗时等诊断数据。
     * @throws \InvalidArgumentException 事件名或级别不是受控编码形式；属于调用方编程错误。
     */
    public function record(string $event, array $context = [], string $level = 'info'): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $event) || !in_array($level, ['error', 'warning', 'notice', 'info'], true)) {
            throw new \InvalidArgumentException('日志事件和级别必须使用固定合同');
        }
        $safe = array_filter(array_intersect_key($context, array_flip(self::FIELDS)), static fn (mixed $value): bool => is_scalar($value) || $value === null);
        foreach ($safe as &$value) { if (is_string($value) && strlen($value) > 512) { $value = mb_strcut($value, 0, 512, 'UTF-8'); } }
        unset($value);
        $this->emit(['event' => $event, 'level' => $level, 'request_id' => $this->context->id()] + $safe, $level);
    }

    /**
     * 主异常保留最多八个无参数调用位置、两个前因的类别/位置；不复制 message、完整 trace 或数据库 errorInfo。
     * 分类是诊断标签，不据此重试外部请求或修改交易状态；重试仍由服务和结构化驱动错误决定。
     */
    public function exception(\Throwable $e): void
    {
        $details = $this->describe($e);
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 32) as $frame) {
            $file = $this->sourcePath($frame['file'] ?? '');
            if ($file === null) { continue; }
            $function = $frame['function'] ?? '';
            $function = str_contains($function, '{closure') ? 'closure' : $this->symbol($function);
            $frames[] = ['file' => $file, 'line' => (int) ($frame['line'] ?? 0),
                'call' => isset($frame['class']) ? $this->symbol($frame['class']) . '::' . $function : $function];
            if (count($frames) === 8) { break; }
        }
        $causes = [];
        for ($cause = $e->getPrevious(); $cause !== null && count($causes) < 2; $cause = $cause->getPrevious()) {
            $causes[] = $this->describe($cause);
        }
        $this->emit(['event' => 'application.exception', 'level' => 'error', 'request_id' => $this->context->id()]
            + $details + ['frames' => $frames, 'causes' => $causes], 'error');
    }

    /** @return array<string,string|int|null> 只从已识别类型提取受控值，未知异常码不当作可公开字段。 */
    private function describe(\Throwable $error): array
    {
        $data = ['exception_class' => $this->symbol($error::class), 'category' => 'unexpected_failure',
            'file' => $this->sourcePath($error->getFile()), 'line' => $error->getLine()];
        if ($error instanceof DatabaseException || $error instanceof \PDOException) {
            $info = $error instanceof DatabaseException ? ($error->getData()['PDO Error Info'] ?? []) : ($error->errorInfo ?? []);
            if (!is_array($info)) { $info = []; }
            $state = $info[$error instanceof DatabaseException ? 'SQLSTATE' : 0] ?? null;
            $driver = $info[$error instanceof DatabaseException ? 'Driver Error Code' : 1] ?? null;
            $driver = is_int($driver) || (is_string($driver) && ctype_digit($driver) && strlen($driver) <= 5) ? (int) $driver : null;
            if (in_array($state, self::SQLSTATES, true)) { $data['sqlstate'] = $state; }
            if (in_array($driver, self::DRIVER_CODES, true)) { $data['driver_code'] = $driver; }
            $data['category'] = match (true) {
                $state === '40001' && $driver === 1213 => 'database_deadlock',
                $state === 'HY000' && $driver === 1205 => 'database_lock_timeout',
                $state === '23000' => 'database_constraint',
                in_array($driver, [2002, 2006, 2013, 2055], true) => 'database_connection',
                in_array($driver, [1044, 1045], true) => 'database_access',
                default => 'database_error',
            };
        } elseif ($error instanceof HttpRequestException || $error instanceof ReceiptCollectionException || $error instanceof ReceiptMessageException) {
            $data['category'] = $error instanceof HttpRequestException ? 'http_transport' : ($error instanceof ReceiptCollectionException ? 'receipt_collection' : 'receipt_message');
            $data['reason_code'] = $error->reasonCode;
            if ($error instanceof HttpRequestException) {
                if ($error->durationMs >= 0 && $error->durationMs <= 4294967295) { $data['duration_ms'] = $error->durationMs; }
                if ($error->httpStatus !== null && $error->httpStatus >= 100 && $error->httpStatus <= 599) { $data['http_status'] = $error->httpStatus; }
            }
        } elseif ($error instanceof PaymentUncertainException) {
            $data['category'] = 'payment_result_unknown';
        } elseif ($error instanceof BusinessException) {
            $data['category'] = 'business_rejection';
            // 常量值是项目合同；不记录任意 RuntimeException 的数字 code。
            if (in_array($error->getCode(), (new \ReflectionClass(ApiResponseConstant::class))->getConstants(), true)) {
                $data['application_code'] = $error->getCode();
            }
        } elseif ($error instanceof \JsonException) { $data['category'] = 'data_format'; }
        elseif ($error instanceof \Error || $error instanceof \LogicException) { $data['category'] = 'program_error'; }
        return $data;
    }

    /** 匿名类名可能携带 NUL、绝对路径和行号；仅记录普通 PHP 标识符，不把动态名称原样输出。 */
    private function symbol(string $name): string
    {
        return strlen($name) <= 192 && preg_match('/^[a-zA-Z_][a-zA-Z0-9_\\\\]*$/D', $name) ? $name : 'dynamic_symbol';
    }

    /** 只保留项目内代码的相对位置，路径不同但同文件名的调用仍可定位；不输出主机目录或外部文件名。 */
    private function sourcePath(string $file): ?string
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 3)) . '/';
        $file = str_replace('\\', '/', $file);
        $matches = DIRECTORY_SEPARATOR === '\\' ? str_starts_with(strtolower($file), strtolower($root)) : str_starts_with($file, $root);
        if (!$matches) { return null; }
        $relative = substr($file, strlen($root));
        if (in_array($relative, ['public/index.php', 'public/router.php', 'think'], true)) { return $relative; }
        return !str_contains($relative, '..') && strlen($relative) <= 240
            && preg_match('~^(app|config|route|vendor|tests)/[a-zA-Z0-9_./-]+\.php$~D', $relative) ? $relative : null;
    }

    /**
     * 强制同步交给框架驱动，捕获其文件警告与异常并清掉未刷缓冲，避免关闭请求时再次抛同一错误。
     * 降级仅写固定字段到 PHP 错误日志；不回显原消息/上下文，更不写进 HTTP 正文。
     *
     * @param array<string,mixed> $payload 只由本类构造的白名单结构。
     */
    private function emit(array $payload, string $level): void
    {
        $failed = false;
        $channel = null;
        $target = null;
        $driverTouched = false;
        set_error_handler(static function (): never { throw new \RuntimeException('技术日志写入失败'); }, E_WARNING | E_USER_WARNING);
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $target = $this->log->getConfig('type_channel.' . $level);
            $driverTouched = true;
            $channel = $this->log->channel($target);
            $channel->write($json, $level);
        } catch (\Throwable) {
            $failed = true;
            if ($driverTouched) {
                try {
                    if ($channel !== null) { $channel->clear(); }
                    // 原生 File 在空缓冲 save 时仍可能创建目录，必须移除失败驱动，避免请求结束再次抛错。
                    $this->log->forgetDriver($target);
                } catch (\Throwable) { /* 无效日志配置同样不能覆盖业务结果。 */ }
            }
        } finally { restore_error_handler(); }
        if (!$failed) { return; }
        $fallback = json_encode(['event' => 'technical_log.unavailable', 'level' => 'error',
            'request_id' => $this->context->id(), 'original_event' => $payload['event']], JSON_THROW_ON_ERROR);
        try {
            if (!@error_log($fallback) && PHP_SAPI === 'cli') { @file_put_contents('php://stderr', $fallback . "\n"); }
        } catch (\Throwable) {
            // 所有技术日志出口都失效时不能伪造持久化保证，也不能用它覆盖主业务的既定结果。
        }
    }
}
