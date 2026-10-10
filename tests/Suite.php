<?php
declare(strict_types=1);

/** 最小可执行测试断言与计数器；一项断言失败即抛出，由顶层入口返回非零退出码。 */
final class Suite
{
    public int $assertions = 0;
    public int $cases = 0;
    /** @var list<array<string,mixed>> 实际运行记录，不保存闭包、参数、比较值或异常原文。 */
    private array $records = [];

    /**
     * 只有用例整体返回后才记录 PASS，避免中途失败的用例计为通过。
     *
     * @param callable():void $test 有实际业务/合同断言的用例。
     */
    public function test(string $name, callable $test): void
    {
        $started = hrtime(true);
        $assertions = $this->assertions;
        try { $test(); }
        catch (Throwable $error) {
            $this->records[] = ['name' => $name, 'status' => 'failed', 'assertions' => $this->assertions - $assertions,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'failure' => self::failureDetails($error)];
            // 先标明失败用例，再由顶层输出异常位置；不序列化闭包参数或测试凭证。
            fwrite(STDERR, "FAIL case: $name\n");
            throw $error;
        }
        $this->records[] = ['name' => $name, 'status' => 'passed', 'assertions' => $this->assertions - $assertions,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1000000)];
        $this->cases++;
        echo "PASS $name\n";
    }

    /** @return list<array<string,mixed>> 只读执行结果，报告不能反向修改计数或补造通过项。 */
    public function results(): array { return $this->records; }

    /** @return array{class:string,file:?string,line:int,causes:list<array>} 无原文/参数的故障位置，前因最多两项。 */
    public static function failureDetails(Throwable $error, ?string $root = null, int $causeLimit = 2): array
    {
        $root = rtrim(str_replace('\\', '/', $root ?? dirname(__DIR__)), '/') . '/';
        $file = str_replace('\\', '/', $error->getFile());
        $relative = str_starts_with($file, $root) ? substr($file, strlen($root)) : null;
        if ($relative !== null && !in_array($relative, ['public/index.php', 'public/router.php', 'think'], true)
            && (!preg_match('~^(app|config|route|vendor|tests|tools)/[a-zA-Z0-9_./-]+\.php$~D', $relative)
            || str_contains($relative, '..'))) { $relative = null; }
        $class = $error::class;
        $result = ['class' => preg_match('/^[a-zA-Z_][a-zA-Z0-9_\\\\]*$/D', $class) ? $class : 'dynamic_exception',
            'file' => $relative, 'line' => $error->getLine(), 'causes' => []];
        for ($cause = $error->getPrevious(); $cause !== null && count($result['causes']) < min(2, $causeLimit); $cause = $cause->getPrevious()) {
            $result['causes'][] = self::failureDetails($cause, $root, 0);
        }
        return $result;
    }

    /** 严格比较值和类型，可发现金额、状态或 ID 被隐式转换导致的合同偏差。 */
    public function same(mixed $expected, mixed $actual): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException('断言不一致：' . var_export($expected, true) . ' / ' . var_export($actual, true));
        }
    }

    public function true(bool $actual): void
    {
        $this->same(true, $actual);
    }

    /**
     * 必须捕获列出的异常类型，可额外核对代码，不能以“任意异常”代替预期失败。
     *
     * @param callable():mixed $operation 预期会抛出异常的操作。
     * @param class-string<Throwable>|list<class-string<Throwable>> $type 允许的异常类别。
     */
    public function throws(callable $operation, string|array $type, ?int $code = null): Throwable
    {
        try {
            $operation();
        } catch (Throwable $e) {
            $types = (array) $type;
            $this->true(count(array_filter($types, static fn (string $class): bool => $e instanceof $class)) > 0);
            if ($code !== null) {
                $this->same($code, (int) $e->getCode());
            }
            return $e;
        }
        throw new RuntimeException('缺少预期异常：' . implode(', ', (array) $type));
    }
}
