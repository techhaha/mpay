<?php
declare(strict_types=1);

namespace app\model\file;

use app\common\base\BaseModel;

/**
 * 本地文件资产的存储元数据，表名显式映射为 ma_file_asset。
 * 模型只记录路径、类型、大小和摘要；上传内容校验及文件读写由文件服务负责。
 *
 * @property string $path
 * @property string $mime_type
 * @property string $size 文件字节数，BIGINT 以字符串表示。
 * @property string $sha256
 */
final class FileRecord extends BaseModel
{
    protected function getOptions(): array
    {
        return [
            'table' => 'ma_file_asset',
            'schema' => [
                'id' => 'string',
                'storage' => 'string',
                'path' => 'string',
                'original_name' => 'string',
                'mime_type' => 'string',
                'size' => 'string',
                'sha256' => 'string',
                'admin_user_id' => 'string',
                'created_at' => 'string',
                'updated_at' => 'string',
            ],
        ];
    }
}
