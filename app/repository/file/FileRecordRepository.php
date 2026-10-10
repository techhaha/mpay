<?php
declare(strict_types=1);

namespace app\repository\file;

use app\common\base\BaseRepository;
use app\model\file\FileRecord;

/**
 * 文件资产元数据读写，不在仓储中执行上传、远端下载或生成不受控公开 URL。
 *
 * @extends BaseRepository<FileRecord>
 */
final class FileRecordRepository extends BaseRepository
{
    public function __construct(FileRecord $model)
    {
        parent::__construct($model);
    }

    /** null 表示历史二维码引用缺失，调用方应拒绝展示而非换用当前账号的另一张图片。 */
    public function find(string $id): ?FileRecord
    {
        return $this->model->find($id);
    }

    /**
     * 调用服务已保存并核验本地图片，元数据与管理员审计由服务在同一事务内提交。
     *
     * @param array{storage:string,path:string,original_name:string,mime_type:string,size:int,sha256:string,admin_user_id:?string} $data
     * @return numeric-string 新文件资产 ID；不能用路径替代业务引用。
     */
    public function create(array $data): string
    {
        return $this->insert($data, ['storage', 'path', 'original_name', 'mime_type', 'size', 'sha256', 'admin_user_id']);
    }
}
