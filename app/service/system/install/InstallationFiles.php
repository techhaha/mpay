<?php
declare(strict_types=1);

namespace app\service\system\install;

use app\common\constant\ApiResponseConstant;
use app\common\util\PrivateFilePath;
use app\exception\BusinessException;
use think\App;

/** 安装专用文件边界：串行锁、重入目标和环境文件；不执行数据库或业务初始化。 */
final class InstallationFiles
{
    public function __construct(private readonly App $app, private readonly PrivateFilePath $paths) {}

    /** 安装锁保存在 private runtime；删除它仍不能绕过初始化服务的非空数据库检查。 */
    public function completed(): bool { return file_exists($this->app->getRuntimePath() . 'install.lock'); }

    /**
     * 浏览器重复点击和不同 PHP 进程共用此锁。锁文件一直保留，由操作系统释放持有关系。
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function exclusive(callable $operation): mixed
    {
        $this->directory($this->app->getRuntimePath());
        $path = $this->path('install-operation.lock');
        $previousMask = umask(0077);
        try { $stream = @fopen($path, 'c+b'); }
        finally { umask($previousMask); }
        if ($stream === false) { throw new \RuntimeException('安装锁文件无法打开'); }
        try {
            if (!flock($stream, LOCK_EX | LOCK_NB)) { throw new BusinessException('安装正在进行，请等待后查看结果', ApiResponseConstant::CONFLICT); }
            return $operation();
        } finally { fclose($stream); }
    }

    /**
     * 首次 DDL 前固定数据库身份；失败后只能对同一个目标继续，密码不保存在进度文件中。
     * @param array{host:string,port:int,name:string,user:string,pass:string} $database
     */
    public function begin(array $database): void
    {
        $target = hash('sha256', json_encode([$database['host'], $database['port'], $database['name']], JSON_THROW_ON_ERROR));
        $path = $this->path('install-pending.json');
        if (is_file($path)) {
            $pending = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($pending) || !is_string($pending['target'] ?? null) || !hash_equals($pending['target'], $target)) {
                throw new BusinessException('已有未完成的安装，请使用原数据库继续', ApiResponseConstant::CONFLICT);
            }
            return;
        }
        $this->write($path, json_encode(['target' => $target, 'started_at' => gmdate('c')], JSON_THROW_ON_ERROR));
    }

    public function pending(): bool { return is_file($this->app->getRuntimePath() . 'install-pending.json'); }

    /** 数据初始化提交后才封闭入口；如果响应丢失，后续访问只进入登录，不重新初始化。 */
    public function complete(): void
    {
        $this->directory($this->app->getRuntimePath());
        $this->write($this->path('install.lock'), json_encode(['installed_at' => gmdate('c')], JSON_THROW_ON_ERROR));
    }

    /**
     * 保留现有文件中的其他配置与注释，只更新本次安装负责的根级键；按照框架 INI_SCANNER_RAW 保存后必须能原样解析。
     * 临时文件完整写入再替换，浏览器永远拿不到数据库密码或密钥文件内容。
     * @param array<string,string> $values 服务器固定键名，禁止从 HTTP 提交任意环境变量。
     */
    public function saveEnvironment(array $values): void
    {
        $path = $this->app->getRootPath() . '.env';
        if (is_link($path)) { throw new \RuntimeException('安装不能替换链接形式的环境文件'); }
        $before = is_file($path) ? file_get_contents($this->paths->existing($path)) : '';
        if (!is_string($before)) { throw new \RuntimeException('环境文件不可读取'); }
        $lines = []; $root = true;
        foreach (preg_split('/\r?\n/', $before) as $line) {
            if (preg_match('/^\s*\[/', $line)) { $root = false; }
            if ($root && preg_match('/^\s*([A-Za-z0-9_]+)\s*=/', $line, $match) && array_key_exists(strtoupper($match[1]), $values)) { continue; }
            $lines[] = $line;
        }
        $prefix = "; MPAY 网页安装配置；其他部署设置保留在下方。\n";
        foreach ($values as $key => $value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]+$/D', $key) || strpbrk($value, "\r\n\0") !== false) { throw new \LogicException('安装环境字段无效'); }
            // INI_SCANNER_RAW 保留反斜杠及内部引号；不得再用 PHP/JSON 的反斜杠转义。
            $prefix .= $key . ' = "' . $value . '"' . "\n";
        }
        $contents = $prefix . implode("\n", $lines);
        $parsed = @parse_ini_string($contents, true, INI_SCANNER_RAW);
        foreach ($values as $key => $value) {
            if (!is_array($parsed) || ($parsed[$key] ?? null) !== $value) { throw new BusinessException('配置包含无法原样保存的字符，请检查输入', ApiResponseConstant::VALIDATION_FAILED); }
        }
        // 拒绝在准备配置内容期间另一个编辑器修改 .env 后仍覆盖其刚写入的内容。
        if ((is_file($path) ? file_get_contents($path) : '') !== $before) { throw new BusinessException('环境文件已被修改，请重新读取后继续', ApiResponseConstant::CONFLICT); }
        $this->write($path, $contents);
    }

    /** 仅处理固定私有目录；拒绝链接，避免安装文件被写到公开资源目录。 */
    private function directory(string $path): void
    {
        if (is_link(rtrim($path, '/\\'))) { throw new \RuntimeException('安装私有目录不能是链接'); }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) { throw new \RuntimeException('无法创建安装私有目录'); }
    }

    private function path(string $name): string
    {
        $path = $this->app->getRuntimePath() . $name;
        if (is_link($path)) { throw new \RuntimeException('安装状态文件不能是链接'); }
        return is_file($path) ? $this->paths->existing($path) : $this->paths->forCreation($path);
    }

    /** 私有文件在同目录独占写临时文件后改名；失败只清自己的临时文件，不删除原文件。 */
    private function write(string $path, string $body): void
    {
        if (is_link($path)) { throw new \RuntimeException('安装文件不能是链接'); }
        $temporary = $this->paths->forCreation($path . '.' . bin2hex(random_bytes(8)) . '.tmp');
        $mask = umask(0077);
        try { $stream = @fopen($temporary, 'xb'); }
        finally { umask($mask); }
        if ($stream === false) { throw new \RuntimeException('安装文件无法写入'); }
        try {
            try {
                if (fwrite($stream, $body) !== strlen($body) || !fflush($stream)) { throw new \RuntimeException('安装文件未完整写入'); }
            } finally { fclose($stream); }
            if (!rename($temporary, $path)) { throw new \RuntimeException('安装文件未完成保存'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
}
