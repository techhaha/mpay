<?php
declare(strict_types=1);

/** 在加载任何测试夹具之前确定模块/用例清单；--list 不启动框架或数据库。 */
final class TestSelection
{
    /** 文件属于首次交付模块；后续模块包含前置模块，避免把仅增量检查当成完整回归。 */
    private const FILES = [
        'unit/foundation.php' => 'p1',
        'unit/framework.php' => 'p1',
        'unit/redesign.php' => 'p2',
        'unit/installation.php' => 'p2',
        'unit/admin_ui.php' => 'p2',
        'unit/catalog_numbering.php' => 'p2',
        'unit/worker_commands.php' => 'p2',
        'integration/redesign.php' => 'p2',
        'integration/numbering.php' => 'p2',
        'integration/default_routing.php' => 'p2',
        'integration/plugin_ownership.php' => 'p2',
        'integration/collection_contract.php' => 'p2',
        'integration/notification_controls.php' => 'p2',
        'integration/schema_comments.php' => 'p2',
    ];

    /** @var array<string,list<string>> 已选择文件及其完整用例集合，保持注册顺序。 */
    public array $files = [];
    public string $module = 'all';
    public bool $integration = false;
    public bool $list = false;
    public ?string $filter = null;

    /** @param list<string> $arguments CLI 参数；未知/重复参数或空选择拒绝，不能零用例显示通过。 */
    public function __construct(array $arguments)
    {
        $seen = [];
        foreach ($arguments as $argument) {
            [$key, $value] = array_pad(explode('=', $argument, 2), 2, null);
            if (isset($seen[$key])) { throw new InvalidArgumentException('测试参数重复'); }
            $seen[$key] = true;
            if ($key === '--module' && in_array($value, ['p1', 'p2', 'all'], true)) { $this->module = $value; }
            elseif ($key === '--integration' && $value === null) { $this->integration = true; }
            elseif ($key === '--list' && $value === null) { $this->list = true; }
            elseif ($key === '--filter' && $value !== null && $value !== '') { $this->filter = $value; }
            else { throw new InvalidArgumentException('测试参数无效：支持 --module=p1|p2|all、--integration、--filter=文本、--list'); }
        }
        foreach (self::FILES as $file => $module) {
            if (($this->module !== 'all' && strcmp($module, $this->module) > 0)
                || (!$this->integration && str_starts_with($file, 'integration/'))) { continue; }
            $names = self::names(__DIR__ . '/' . $file);
            if ($this->filter !== null && !str_contains($file, $this->filter)
                && !array_filter($names, fn (string $name): bool => str_contains($name, $this->filter))) { continue; }
            // 集成场景可能共享前序事实；选中其中一个名称时执行同文件整组，清单明确显示依赖。
            $this->files[$file] = $names;
        }
        if ($this->files === []) { throw new InvalidArgumentException('筛选没有匹配任何测试'); }
    }

    /**
     * 静态读取 $suite->test 的单引号名称，不 require 测试文件或执行表达式。
     * 用例筛选以完整夹具文件为最小执行单元，避免过滤后破坏前序状态却误报业务失败。
     * --list 只解析名称，不启动应用或触及数据库。
     *
     * @return list<string>
     */
    public static function names(string $file): array
    {
        $tokens = token_get_all(file_get_contents($file));
        $tokens = array_values(array_filter($tokens, static fn (array|string $token): bool =>
            !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $names = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$suite'
                || ($tokens[$index + 1][1] ?? null) !== '->' || ($tokens[$index + 2][1] ?? null) !== 'test') { continue; }
            $name = $tokens[$index + 4] ?? null;
            if (!is_array($name) || $name[0] !== T_CONSTANT_ENCAPSED_STRING || $name[1][0] !== "'") {
                throw new RuntimeException('测试清单要求静态单引号用例名');
            }
            $names[] = preg_replace_callback("/\\\\([\\\\'])/", static fn (array $match): string => $match[1], substr($name[1], 1, -1));
        }
        if ($names === []) { throw new RuntimeException('登记的测试文件没有可列出的用例：' . basename($file)); }
        return $names;
    }
}
