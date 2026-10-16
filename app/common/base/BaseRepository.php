<?php
declare(strict_types=1);

namespace app\common\base;

/**
 * 仓储共用模型注入、事务前提及写字段白名单，查询直接使用模型的原生链式方法。
 * ThinkORM 4 的模型动态查询每次创建独立 Query；不再包一层同义 query()，也不在仓储开启业务事务。
 *
 * @template TModel of BaseModel
 */
abstract class BaseRepository
{
    /** @param TModel $model 当前仓储的主模型；其他模型由具体仓储显式注入。 */
    public function __construct(protected readonly BaseModel $model) {}

    /**
     * 检查服务是否已开启同连接事务，只读 PDO 状态，不开启、提交或回滚事务。
     * 用于 FOR UPDATE 和约定的组合写入前提；重复检查不会产生嵌套事务，也不代替业务锁序核验。
     */
    protected function requireTransaction(): void
    {
        if (!$this->model->getConnection()->connect()->inTransaction()) {
            throw new \LogicException('锁定及写入必须在服务拥有的事务内执行');
        }
    }

    /**
     * 将服务已校验的字段写入主模型；跨表插入才显式指定另一个已注入模型，额外字段直接拒绝。
     * 使用原生 insertGetId，不在共享模型实例上 save，以免留下实体状态；新增时间仍由表默认值提供。
     * 此公共入口用于服务事务内的业务写入；这不是 ORM 对全部单句写入的强制要求。
     *
     * @param array<string,scalar|null> $data JSON 列由调用方按明确合同编码，不接收原始请求数组。
     * @param list<string> $fields 该仓储操作的固定可写字段。
     * @param BaseModel|null $model 非 null 时必须与主模型使用同一框架连接。
     * @return numeric-string 新建记录 ID。
     */
    protected function insert(array $data, array $fields, ?BaseModel $model = null): string
    {
        $this->requireTransaction();
        if (array_diff(array_keys($data), $fields) !== []) {
            throw new \InvalidArgumentException('存在不允许写入的字段');
        }
        if ($model !== null && $model->getConnection() !== $this->model->getConnection()) {
            throw new \LogicException('同一仓储写入必须使用同一事务连接');
        }
        return (string) ($model ?? $this->model)->insertGetId($data);
    }
}
