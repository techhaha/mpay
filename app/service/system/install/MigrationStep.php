<?php
declare(strict_types=1);

namespace app\service\system\install;

use RuntimeException;

/** 一条新装建表定义及摘要；SQL 是唯一结构来源，不接受历史 ALTER 或升级回调。 */
final readonly class MigrationStep
{
    private function __construct(
        public string $version,
        public string $name,
        public string $table,
        public string $sql,
        public string $checksum,
    ) {
    }

    /**
     * @param mixed $definition 程序内基线文件的单条 CREATE TABLE，不接受请求输入。
     * @throws RuntimeException 版本、表名或 SQL 形式不符合新装合同。
     */
    public static function fromDefinition(string $version, string $name, mixed $definition): self
    {
        if (!preg_match('/^[0-9]{12}_[a-z0-9_]+$/D', $version) || strlen($version) > 64
            || !preg_match('/^ma_[a-z0-9_]{1,61}$/D', $name)
            || !is_string($definition)
            || !preg_match('/^CREATE TABLE ' . preg_quote($name, '/') . '\\s*\\(/i', $definition)
            || str_contains($definition, ';')) {
            throw new RuntimeException('迁移步骤必须是有效版本中与表名对应的单条 CREATE TABLE');
        }
        // 摘要取 SQL 原字节；已经执行的步骤不得更换定义。
        return new self($version, $name, $name, $definition, hash('sha256', $definition));
    }

    public function identity(): string
    {
        return $this->version . ':' . $this->name;
    }

}
