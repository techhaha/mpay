<?php
declare(strict_types=1);

/**
 * CLI 测试的机器报告：记录真实执行、运行环境、源码及迁移身份，不读取业务环境或保存异常原文。
 * 与源码包工具共用显式文件边界；没有提供可恢复快照时明确标记，不能把单次测试报告当交付包。
 */
final class TestReport
{
    private readonly string $root;
    private readonly string $startedAt;
    private readonly int $started;
    private readonly string $runId;
    /** @var array<string,string> 相对文件到 SHA-256，运行数据和凭证不在范围内。 */
    private array $source = [];
    private ?Throwable $setupError = null;
    /** @var array{manifest_sha256:string,archive_sha256:string}|null */
    private ?array $snapshot = null;
    private ?string $snapshotFile = null;

    /** @param array<string,mixed> $selection 入口生成的测试选择，不传环境变量、凭证或自由输入对象。 */
    public function __construct(string $root, private readonly string $entry, private readonly array $selection = [], ?string $snapshotFile = null)
    {
        $this->root = str_replace('\\', '/', realpath($root) ?: throw new RuntimeException('测试项目目录不存在'));
        $this->startedAt = gmdate('c');
        $this->started = hrtime(true);
        $this->runId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8));
        try {
            $this->source = self::sourceFiles($this->root);
            if ($snapshotFile !== null) { $this->snapshotFile = $snapshotFile; $this->snapshot = $this->snapshot($snapshotFile); }
        } catch (Throwable $error) { $this->setupError = $error; }
    }

    /** 源码或指定快照不完整时先拒绝执行；调用者随后仍可写出失败报告。 */
    public function assertReady(): void
    {
        if ($this->setupError !== null) { throw new RuntimeException('测试源码或快照核验失败', 0, $this->setupError); }
    }

    /**
     * 只在所有清理逻辑结束后调用。源码变化、零用例、清单遗漏、失败或报告写入错误都不能显示通过。
     *
     * @param array<string,bool|int|string|null> $cleanup 由持有实例/资源的入口明确确认，不靠本类猜测已清理。
     * @return array{path:string,status:string} path 为项目内相对位置。
     */
    public function finish(Suite $suite, ?Throwable $failure = null, array $cleanup = []): array
    {
        $failure ??= $this->setupError;
        $current = [];
        try { $current = self::sourceFiles($this->root); }
        catch (Throwable $error) { $failure ??= $error; }
        if ($this->snapshotFile !== null && $this->setupError === null) {
            try {
                if ($this->snapshot($this->snapshotFile) !== $this->snapshot) { throw new RuntimeException('验收期间快照发生变化'); }
            } catch (Throwable $error) { $failure ??= $error; }
        }
        $unchanged = $this->source !== [] && $this->source === $current;
        $expected = isset($this->selection['files']) ? array_merge(...array_values($this->selection['files'])) : null;
        $records = $suite->results();
        $complete = $expected === null || $expected === array_column($records, 'name');
        $failedCases = count(array_filter($records, static fn (array $record): bool => $record['status'] === 'failed'));
        $extensions = [];
        foreach (get_loaded_extensions() as $name) { $extensions[$name] = phpversion($name) ?: 'builtin'; }
        ksort($extensions, SORT_STRING);
        $packages = [];
        try {
            if (class_exists(Composer\InstalledVersions::class)) {
                foreach (Composer\InstalledVersions::getInstalledPackages() as $name) {
                    $packages[$name] = Composer\InstalledVersions::getPrettyVersion($name);
                }
                ksort($packages, SORT_STRING);
            }
            $migrations = [];
            foreach (glob($this->root . '/database/migrations/*.php') ?: [] as $file) {
                $steps = [];
                if (class_exists(app\service\system\install\MigrationStep::class)) {
                    foreach (require $file as $name => $definition) {
                        $step = app\service\system\install\MigrationStep::fromDefinition(pathinfo($file, PATHINFO_FILENAME), $name, $definition);
                        $steps[$name] = $step->checksum;
                    }
                }
                $migrations[] = ['version' => pathinfo($file, PATHINFO_FILENAME), 'file_sha256' => hash_file('sha256', $file), 'steps' => $steps];
            }
        } catch (Throwable $error) { $failure ??= $error; }
        $migrations ??= [];
        $passed = $failure === null && $unchanged && $complete && $suite->cases > 0 && $failedCases === 0 && !in_array(false, $cleanup, true);
        $data = ['format' => 1, 'run_id' => $this->runId, 'status' => $passed ? 'passed' : 'failed',
            'entry' => $this->entry, 'selection' => $this->selection, 'selection_complete' => $complete,
            'started_at' => $this->startedAt, 'finished_at' => gmdate('c'), 'duration_ms' => (int) ((hrtime(true) - $this->started) / 1000000),
            'runtime' => ['php' => PHP_VERSION, 'os_family' => PHP_OS_FAMILY, 'integer_bits' => PHP_INT_SIZE * 8,
                'extensions' => $extensions, 'packages' => $packages,
                'databases' => class_exists(TestDatabase::class, false) ? TestDatabase::observedProfiles() : []],
            'counts' => ['passed' => $suite->cases, 'failed' => $failedCases, 'assertions' => $suite->assertions],
            'cases' => $records, 'failure' => $failure === null ? null : Suite::failureDetails($failure, $this->root),
            'source' => ['unchanged' => $unchanged, 'files' => $this->source, 'changed_files' => array_values(array_unique([
                ...array_keys(array_diff_assoc($this->source, $current)), ...array_keys(array_diff_assoc($current, $this->source)),
            ]))],
            'migrations' => $migrations, 'snapshot' => $this->snapshot, 'cleanup' => $cleanup,
            'limits' => $this->snapshot === null ? ['recoverable_source_snapshot_not_supplied'] : []];
        $directory = $this->root . '/runtime/tests/reports';
        foreach (['runtime', 'runtime/tests', 'runtime/tests/reports'] as $part) {
            if (is_link($this->root . '/' . $part)) { throw new RuntimeException('报告目录不能使用符号链接'); }
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new RuntimeException('报告目录无法建立'); }
        $relative = 'runtime/tests/reports/' . $this->runId . '.json';
        $path = $this->root . '/' . $relative;
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        // 先完整落地再改名；中断留下 .part，不会让读取者把半份报告解释成通过。
        $stream = fopen($path . '.part', 'xb');
        if ($stream === false) { throw new RuntimeException('无法创建本次报告'); }
        try { if (fwrite($stream, $json) !== strlen($json) || !fflush($stream)) { throw new RuntimeException('报告未完整写入'); } }
        finally { fclose($stream); }
        if (!rename($path . '.part', $path)) { throw new RuntimeException('报告未完成发布'); }
        return ['path' => $relative, 'status' => $data['status']];
    }

    /** @return array<string,string> 与 tools/acceptance_snapshot.py 相同的文件清单；拒绝源文件链接和越界别名。 */
    public static function sourceFiles(string $root): array
    {
        $root = str_replace('\\', '/', realpath($root) ?: throw new RuntimeException('源码目录不存在'));
        $policy = json_decode(file_get_contents($root . '/tools/source-policy.json'), true, 16, JSON_THROW_ON_ERROR);
        if (($policy['format'] ?? null) !== 1) { throw new RuntimeException('源码清单格式不受支持'); }
        foreach (['directories', 'root_files', 'suffixes', 'special_names'] as $field) {
            if (!is_array($policy[$field] ?? null) || !array_is_list($policy[$field])
                || array_filter($policy[$field], static fn (mixed $value): bool => !is_string($value)) !== []) {
                throw new RuntimeException('源码清单字段无效');
            }
        }
        $files = [];
        foreach ($policy['root_files'] as $name) {
            $path = self::declaredPath($root, $name);
            if (is_file($path)) { $files[$name] = self::fileHash($path); }
        }
        foreach ($policy['directories'] as $name) {
            $path = self::declaredPath($root, $name);
            if (!is_dir($path)) { continue; }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
                self::declaredPath($root, $relative);
                if ($file->isFile() && (in_array('.' . strtolower($file->getExtension()), $policy['suffixes'], true)
                    || in_array($file->getFilename(), $policy['special_names'], true))) { $files[$relative] = self::fileHash($file->getPathname()); }
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function fileHash(string $path): string
    {
        return hash_file('sha256', $path) ?: throw new RuntimeException('源码文件无法读取');
    }

    /** 声明仅为项目内相对路径；源目录/根文件同样不能借链接指向运行数据或项目外内容。 */
    private static function declaredPath(string $root, string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, ':')
            || array_intersect(explode('/', $relative), ['.', '..', '']) !== []) { throw new RuntimeException('源码路径必须位于项目内'); }
        $path = $root . '/' . $relative;
        $real = realpath($path);
        $same = $real === false ? true : (DIRECTORY_SEPARATOR === '\\'
            ? strtolower(str_replace('\\', '/', $real)) === strtolower($path) : $real === $path);
        if (is_link($path) || !$same) { throw new RuntimeException('源码清单不能包含链接或路径别名'); }
        return $path;
    }

    /** @return array{manifest_sha256:string,archive_sha256:string} 指定快照必须可恢复且与运行源码完整一致。 */
    private function snapshot(string $file): array
    {
        $path = realpath($file);
        if ($path === false || !str_starts_with(str_replace('\\', '/', $path), $this->root . '/runtime/')
            || !is_file($path) || filesize($path) > 1048576) { throw new RuntimeException('验收快照必须位于项目 runtime 内'); }
        $raw = file_get_contents($path);
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $archive = dirname($path) . '/source.tar.gz';
        if (($manifest['format'] ?? null) !== 1 || ($manifest['files'] ?? null) !== $this->source
            || !is_string($manifest['archive_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['archive_sha256'])
            || !is_file($archive) || is_link($archive) || self::fileHash($archive) !== $manifest['archive_sha256']) {
            throw new RuntimeException('快照源码、归档或摘要不一致');
        }
        // 摘要相符仍不足以证明归档能恢复源码；逐项读取归档文件，拒绝替代内容、链接及多余文件。
        $bundle = new PharData($archive);
        foreach ($this->source as $name => $digest) {
            if (!isset($bundle[$name]) || !$bundle[$name]->isFile() || $bundle[$name]->isLink()
                || $bundle[$name]->getSize() !== filesize($this->root . '/' . $name)
                || hash('sha256', $bundle[$name]->getContent()) !== $digest) {
                throw new RuntimeException('快照归档内容不符合源码清单');
            }
        }
        $count = 0;
        foreach (new RecursiveIteratorIterator($bundle) as $item) {
            if ($item->isLink() || !$item->isFile()) { throw new RuntimeException('快照含非普通文件'); }
            $count++;
        }
        if ($count !== count($this->source)) { throw new RuntimeException('快照归档含额外文件'); }
        return ['manifest_sha256' => hash('sha256', $raw), 'archive_sha256' => $manifest['archive_sha256']];
    }
}
