<?php
declare(strict_types=1);

namespace app\repository\system;

use app\common\base\BaseRepository;
use app\model\admin\AdminUser;
use DateTimeImmutable;

/**
 * 管理员身份持久化；密码校验和 Session 生命周期由认证/动作服务处理。
 *
 * @extends BaseRepository<AdminUser>
 */
final class AdminUserRepository extends BaseRepository
{
    public function __construct(AdminUser $model)
    {
        parent::__construct($model);
    }

    /** 密码再次确认用的内部读取；password_hash 不能输出到 API。 */
    public function find(string $id): ?AdminUser
    {
        return $this->model->find($id);
    }

    /** 安装状态读取不验证密码，也不据此发放 Session。 */
    public function singleton(): ?AdminUser
    {
        return $this->model->where('singleton_slot', 1)->find();
    }

    /** 初始化服务在已核对空业务库的事务内创建唯一管理员，密码原文不得交给仓储。 */
    public function create(string $username, string $passwordHash): string
    {
        return $this->insert(
            ['username' => $username, 'password_hash' => $passwordHash],
            ['username', 'password_hash'],
        );
    }

    /** 单管理员登录先锁固定槽位，未知用户名也走同一锁，使失败计数与下一次认证串行。 */
    public function lockSingleton(): ?AdminUser
    {
        $this->requireTransaction();
        return $this->model->where('singleton_slot', 1)->lock(true)->find();
    }

    /**
     * 调用服务已锁定管理员；密码变更与认证版本、操作审计在同一事务提交。
     * 版本是否递增由服务决定，仓储不自行撤销会话，也不处理原密码。
     */
    public function updatePassword(
        string $id,
        int $expectedVersion,
        string $hash,
        int $newVersion,
        DateTimeImmutable $now,
    ): void {
        $this->requireTransaction();
        $changed = $this->model
            ->where('id', $id)
            ->where('session_version', $expectedVersion)
            ->update([
                'password_hash' => $hash,
                'session_version' => $newVersion,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        if ($changed !== 1) {
            throw new \LogicException('已锁定管理员的密码写入未完成');
        }
    }

    /** 服务已锁定身份并决定撤销版本；只更新认证版本，不删除 Session 文件或改变密码。 */
    public function updateSessionVersion(
        string $id,
        int $expectedVersion,
        int $newVersion,
        DateTimeImmutable $now,
    ): void {
        $this->requireTransaction();
        $changed = $this->model
            ->where('id', $id)
            ->where('session_version', $expectedVersion)
            ->update([
                'session_version' => $newVersion,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        if ($changed !== 1) {
            throw new \LogicException('已锁定管理员的认证版本写入未完成');
        }
    }

    /** 管理动作先锁管理员，再锁业务单；改密/停用应递增 session_version，使旧授权失效。 */
    public function lock(string $id): ?AdminUser
    {
        $this->requireTransaction();
        return $this->model->where('id', $id)->lock(true)->find();
    }
}
