<?php
declare(strict_types=1);

namespace app\repository\payment\runtime;

use app\common\base\BaseRepository;
use app\model\payment\ReceiptSession;

/** @extends BaseRepository<ReceiptSession> 同登录身份的持久互斥及会话读写；不执行登录网络请求。 */
final class ReceiptSessionRepository extends BaseRepository
{
    public function __construct(ReceiptSession $model) { parent::__construct($model); }

    /** 由配置保存事务预建；同一账号多配置复用一行，账号凭证不同时允许保存但采集拒绝冲突。 */
    public function ensure(string $key, string $fingerprint): void
    {
        $this->requireTransaction();
        if ($this->lock($key) === null) {
            $this->insert(['session_key'=>$key,'auth_fingerprint'=>$fingerprint], ['session_key','auth_fingerprint']);
        }
    }

    public function lock(string $key): ?ReceiptSession
    {
        $this->requireTransaction();
        return $this->model->where('session_key', $key)->lock(true)->find();
    }

    /** 调用方已经核对所有启用配置的凭证一致；认证材料改变才清会话，普通插件参数编辑不会清理。 */
    public function claim(ReceiptSession $row, string $fingerprint, string $token, string $until): ?array
    {
        $this->requireTransaction();
        $same = hash_equals($row['auth_fingerprint'], $fingerprint);
        $this->model->where('id', $row['id'])->update(['auth_fingerprint'=>$fingerprint,
            'session_json'=>$same && $row['session_json'] !== null ? json_encode($row['session_json'], JSON_THROW_ON_ERROR) : null,
            'claim_token'=>$token,'lease_until'=>$until]);
        return $same ? $row['session_json'] : null;
    }

    /** 原租约有效才能回写；null 代表清空，调用方区分无更新与认证失效。 */
    public function save(ReceiptSession $row, ?array $session): void
    {
        $this->requireTransaction();
        $this->model->where('id', $row['id'])->where('claim_token', $row['claim_token'])->update([
            'session_json'=>$session === null ? null : json_encode($session, JSON_THROW_ON_ERROR)]);
    }

    public function release(ReceiptSession $row): void
    {
        $this->requireTransaction();
        $this->model->where('id', $row['id'])->where('claim_token', $row['claim_token'])->update(['claim_token'=>null,'lease_until'=>null]);
    }
}
