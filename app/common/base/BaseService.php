<?php
declare(strict_types=1);

namespace app\common\base;

use think\db\exception\PDOException as DatabaseException;
use think\facade\Db;

/**
 * 服务层统一事务入口，复用 ThinkPHP 已绑定的数据库连接和事务管理。
 * 普通事务不重放业务；只有调用方明确选择 transactionRetry 的纯数据库用例才允许有限重试。
 */
abstract class BaseService
{
    /** 组合用例的内部入口只检查既有事务，不创建嵌套事务或增加提交层数。 */
    protected function requireTransaction(): void
    {
        if (!Db::connect()->connect()->inTransaction()) {
            throw new \LogicException('此内部操作要求调用方已经开启事务');
        }
    }

    /**
     * 当前服务拥有完整事务，正常返回提交，异常由框架回滚并原样抛出。
     * 闭包内不能访问外部网络；文件及其他外部副作用也不受数据库回滚保护。
     *
     * @template T
     * @param callable():T $callback
     * @return T
     * @throws \LogicException 已有外层事务，应调用同事务内部方法而非再次开事务。
     */
    protected function transaction(callable $callback): mixed
    {
        $connection = Db::connect();
        if ($connection->connect()->inTransaction()) {
            throw new \LogicException('事务必须由最外层服务统一拥有');
        }
        return $connection->transaction($callback);
    }

    /**
     * 只重试数据库明确报告的死锁/锁等待超时；连接中断、提交结果不明、唯一冲突和业务拒绝均直接抛出。
     * 每轮重跑整个已回滚闭包，调用方须在闭包内重新读取/锁定状态，不能复用前一轮被修改的 Model。
     * 不用于 HTTP、邮件、文件写入等外部副作用，也不会为普通 transaction 隐式启用重试。
     *
     * @template T
     * @param callable():T $callback 可从头重跑的纯数据库工作。
     * @param int $attempts 包括首次执行的总次数，1～5。
     * @param int $sleepMs 两次执行之间等待的毫秒数，0～500。
     * @return T
     */
    protected function transactionRetry(
        callable $callback,
        int $attempts = 3,
        int $sleepMs = 50,
    ): mixed {
        if ($attempts < 1 || $attempts > 5 || $sleepMs < 0 || $sleepMs > 500) {
            throw new \InvalidArgumentException('事务重试次数或间隔超出边界');
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->transaction($callback);
            } catch (\Throwable $exception) {
                if ($attempt >= $attempts || !$this->retryableDatabaseError($exception)) {
                    throw $exception;
                }
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }
    }

    /** 读取驱动结构化错误码，不靠包含 SQL/秘密的错误文本猜测，也不把连接故障当可安全重试。 */
    private function retryableDatabaseError(\Throwable $exception): bool
    {
        if ($exception instanceof DatabaseException) {
            // ThinkORM 4 的包装异常不保留原 PDO previous，但提供结构化 PDO Error Info。
            $info = $exception->getData()['PDO Error Info'] ?? [];
            $state = $info['SQLSTATE'] ?? null;
            $driver = $info['Driver Error Code'] ?? null;
        } elseif ($exception instanceof \PDOException) {
            $state = $exception->errorInfo[0] ?? null;
            $driver = $exception->errorInfo[1] ?? null;
        } else {
            return false;
        }
        return ($state === '40001' && (int) $driver === 1213) ||
            ($state === 'HY000' && (int) $driver === 1205);
    }
}
