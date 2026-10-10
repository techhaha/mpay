<?php
declare(strict_types=1);

namespace app\service\system\install;

/**
 * 一条受控建表定义及其摘要；SQL 是唯一结构来源，不另存完整元数据。
 * 除建表外，接受三种固定追加列声明及纯注释步骤；不接受任意 ALTER 或可执行升级回调。
 */
final readonly class MigrationStep
{
    private function __construct(
        public string $version,
        public string $name,
        public string $table,
        public string $sql,
        public string $checksum,
        public ?string $addedColumn = null,
        public ?string $columnDefinition = null,
        /** @var array{comment:string,columns:array<string,string>}|null 不包含类型、默认值或其他结构属性。 */
        public ?array $comments = null,
    ) {
    }

    /**
     * @param mixed $definition 版本文件返回的建表 SQL、受限追加列或纯注释声明；不接受请求输入或可执行回调。
     * @throws \RuntimeException 版本、名称或声明不符合本项目的受限迁移合同。
     */
    public static function fromDefinition(string $version, string $name, mixed $definition): self
    {
        if (is_array($definition)) {
            if (array_key_exists('comments', $definition)) {
                $table = $definition['table'] ?? null;
                $comments = $definition['comments'];
                if (array_diff(array_keys($definition), ['table', 'comments']) !== []
                    || !preg_match('/^[0-9]{12}_[a-z0-9_]+$/D', $version) || strlen($version) > 64
                    || !is_string($table) || ($table !== '_mpay_migration_step' && !preg_match('/^ma_[a-z0-9_]{1,61}$/D', $table))
                    || $name !== $table . '_comments' || strlen($name) > 64
                    || !is_array($comments) || array_diff(array_keys($comments), ['comment', 'columns']) !== []
                    || !self::validComment($comments['comment'] ?? null, 2048)
                    || !is_array($comments['columns'] ?? null) || $comments['columns'] === []) {
                    throw new \RuntimeException('注释迁移只能声明所属表及中文说明');
                }
                foreach ($comments['columns'] as $column => $comment) {
                    if (!is_string($column) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column)
                        || !self::validComment($comment, 1024)) {
                        throw new \RuntimeException('字段注释必须是有效字段名和有界单行 UTF-8 文本');
                    }
                }
                // 注释步骤记录声明摘要；不改写旧 CREATE/ADD SQL 或既有账本摘要。
                $checksum = hash('sha256', json_encode([$table, $comments], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                return new self($version, $name, $table, '', $checksum, comments: $comments);
            }
            $table = $definition['table'] ?? null;
            $column = $definition['column'] ?? null;
            $sql = $definition['sql'] ?? null;
            $prefix = 'ALTER TABLE ' . (is_string($table) ? $table : '') . ' ADD COLUMN ' . (is_string($column) ? $column : '') . ' ';
            $declaration = is_string($sql) && str_starts_with($sql, $prefix) ? substr($sql, strlen($prefix)) : '';
            if (array_diff(array_keys($definition), ['table', 'column', 'sql']) !== []
                || !preg_match('/^[0-9]{12}_[a-z0-9_]+$/D', $version) || strlen($version) > 64
                || !is_string($table) || !preg_match('/^ma_[a-z0-9_]{1,61}$/D', $table)
                || !is_string($column) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column)
                || $name !== $table . '_' . $column || strlen($name) > 64
                || !in_array($declaration, ['DATETIME NULL DEFAULT NULL', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'TINYINT UNSIGNED NOT NULL DEFAULT 1'], true)) {
                throw new \RuntimeException('列追加迁移必须使用项目支持的明确列声明');
            }
            return new self($version, $name, $table, $sql, hash('sha256', $sql), $column, $declaration);
        }
        if (!preg_match('/^[0-9]{12}_[a-z0-9_]+$/D', $version) || strlen($version) > 64
            || !preg_match('/^ma_[a-z0-9_]{1,61}$/D', $name)
            || !is_string($definition)
            || !preg_match('/^CREATE TABLE ' . preg_quote($name, '/') . '\\s*\\(/i', $definition)
            || str_contains($definition, ';')) {
            throw new \RuntimeException('迁移步骤必须是有效版本中与表名对应的单条 CREATE TABLE');
        }
        // 摘要直接取 SQL 原字节；已有安装进度仍能核对同一步骤，不因执行器简化而重建表。
        return new self($version, $name, $name, $definition, hash('sha256', $definition));
    }

    public function identity(): string
    {
        return $this->version . ':' . $this->name;
    }

    private static function validComment(mixed $value, int $maxBytes): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $maxBytes
            && mb_check_encoding($value, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $value);
    }
}
