<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\service\file\FileAssetService;
use think\file\UploadedFile;
use think\Validate;

/** 单张收款图片的 multipart 边界；原生文件规则检查大小/MIME，实际解码、像素边界及重编码由文件服务负责。 */
final class ImageUploadValidator extends Validate
{
    protected $rule = [
        'image' => ['require', 'validUpload', 'fileSize' => FileAssetService::MAX_UPLOAD_BYTES, 'fileMime' => 'image/png,image/jpeg,image/webp'],
    ];
    protected $message = ['image' => '请选择一张完整的 PNG、JPEG 或 WebP 图片，最多 2 MiB'];

    /**
     * @param array<string,mixed> $fields multipart 普通字段，不与 query 合并。
     * @param array<string,mixed> $files 框架解析的全部文件，拒绝数组上传及额外文件。
     * @return array{image:UploadedFile}
     */
    public function upload(array $fields, array $files): array
    {
        if ($fields !== [] || array_keys($files) !== ['image']) {
            throw new BusinessException('上传仅接受一张 image 图片', ApiResponseConstant::VALIDATION_FAILED);
        }
        $values = $fields + $files;
        if (!(clone $this)->failException(false)->check($values)) {
            throw new BusinessException('上传图片无效，请检查类型、大小及完整性', ApiResponseConstant::VALIDATION_FAILED);
        }
        return $values;
    }

    protected function validUpload(mixed $value): bool { return $value instanceof UploadedFile && $value->isValid(); }
}
