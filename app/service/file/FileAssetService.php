<?php
declare(strict_types=1);

namespace app\service\file;

use app\common\base\BaseService;
use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;
use app\model\file\FileRecord;
use app\repository\file\FileRecordRepository;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\audit\ConfigurationAuditService;
use think\App;
use think\File;
use think\Filesystem;

/**
 * 验证并登记本地收款图片，将已有资产解析为同源公开路径；不下载远端材料，不改写历史二维码引用。
 * 文件缺失或越界时失败，调用者应在认领支付执行之前核验，避免误记为已调用平台。
 */
final class FileAssetService extends BaseService
{
    public const MAX_UPLOAD_BYTES = 2097152;
    public const MAX_STORED_BYTES = 4194304;
    public const MAX_EDGE = 4096;
    public const MAX_PIXELS = 4194304;

    public function __construct(
        private readonly FileRecordRepository $files,
        private readonly App $app,
        private readonly Filesystem $filesystem,
        private readonly ConfigurationAuditService $audit,
    ) {}

    /**
     * PNG/JPEG/WebP 在解码前限制字节和像素，解码后重新输出 PNG，去掉原始容器元数据及附加内容。
     * 不缩放或猜测收款账号；上传成功只登记图片，通道绑定和扫码核验另行完成。
     *
     * @param File $file HTTP 入口须使用通过 isValid 的 UploadedFile；CLI/隔离测试可提供本地 File。
     * @return array{id:string,url:string,mime_type:string,size:int,width:int,height:int}
     * @throws BusinessException 图片格式/大小无效或管理员身份已失效。
     * @throws \RuntimeException 存储或数据库失败；不得因此声称图片或审计已经提交。
     */
    public function uploadImage(File $file, ConfigurationActionContext $actor): array
    {
        // 解码属于有界 CPU/内存工作，放在事务外；提交前仍在事务内重新核验管理员版本。
        $this->transaction(fn() => $this->audit->authorize($actor));
        $localPath = $file->getRealPath();
        if ($localPath === false || !is_file($localPath)) {
            throw new \RuntimeException('上传材料必须是已存在的本地文件');
        }
        $content = file_get_contents($localPath, false, null, 0, self::MAX_UPLOAD_BYTES + 1);
        if ($content === false) {
            throw new \RuntimeException('上传临时文件无法读取');
        }
        if ($content === '' || strlen($content) > self::MAX_UPLOAD_BYTES) {
            throw new BusinessException(
                '图片不能为空且最多 2 MiB',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $info = @getimagesizefromstring($content);
        if (
            $info === false ||
            !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) ||
            $info[0] < 1 ||
            $info[1] < 1 ||
            $info[0] > self::MAX_EDGE ||
            $info[1] > self::MAX_EDGE ||
            $info[0] * $info[1] > self::MAX_PIXELS
        ) {
            throw new BusinessException(
                '请上传 PNG、JPEG 或 WebP，单边最多 4096 像素且总像素最多 4194304',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            throw new \RuntimeException('图片处理扩展不可用');
        }
        $image = @imagecreatefromstring($content);
        unset($content);
        if ($image === false) {
            throw new BusinessException(
                '图片内容无法完整解码',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false) {
            imagedestroy($image);
            throw new \RuntimeException('图片暂存流无法创建');
        }
        try {
            if (imagesx($image) !== $info[0] || imagesy($image) !== $info[1]) {
                throw new BusinessException(
                    '图片尺寸与解码内容不一致',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
            imagesavealpha($image, true);
            if (!imagepng($image, $stream, 6)) {
                throw new \RuntimeException('图片重新编码失败');
            }
            $stat = fstat($stream);
            $size = $stat === false ? 0 : $stat['size'];
            if ($size < 1 || $size > self::MAX_STORED_BYTES) {
                throw new BusinessException(
                    '图片重新编码后超过 4 MiB，请先减小尺寸',
                    ApiResponseConstant::VALIDATION_FAILED,
                );
            }
            rewind($stream);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $digest = hash_final($hash);
            rewind($stream);
            $expectedRoot =
                rtrim(str_replace('\\', '/', $this->app->getRootPath()), '/') . '/public/storage';
            $configuredRoot = rtrim(
                str_replace('\\', '/', (string) $this->filesystem->getDiskConfig('public', 'root')),
                '/',
            );
            if (
                $this->filesystem->getDiskConfig('public', 'type') !== 'local' ||
                $configuredRoot !== $expectedRoot
            ) {
                throw new \RuntimeException('收款图片仅支持本项目 public/storage 本地磁盘');
            }
            $path = 'qr/' . date('Ym') . '/' . bin2hex(random_bytes(16)) . '.png';
            $id = $this->transaction(function () use (
                $actor,
                $stream,
                $path,
                $size,
                $digest,
            ): string {
                $this->audit->authorize($actor);
                // 原文件名、客户端 MIME 和扩展名均不参与路径。只写重新编码的随机 PNG，禁止覆盖旧资产。
                $disk = $this->filesystem->disk('public');
                if ($disk->fileExists($path)) {
                    throw new \RuntimeException('图片随机路径冲突');
                }
                $disk->writeStream($path, $stream, ['visibility' => 'public']);
                $id = $this->files->create([
                    'storage' => 'local',
                    'path' => $path,
                    'original_name' => 'qr-code.png',
                    'mime_type' => 'image/png',
                    'size' => $size,
                    'sha256' => $digest,
                    'admin_user_id' => $actor->adminId,
                ]);
                $this->audit->record($actor, 'file.upload', 'file_asset', $id, 'created', [
                    'mime_type' => 'image/png',
                    'size' => $size,
                    'sha256' => $digest,
                ]);
                return $id;
            });
            // 文件系统不能随数据库回滚。提交结果不明时保留新文件，避免误删已经被数据库登记的资产；孤立文件由维护核对。
            return [
                'id' => $id,
                'url' => '/storage/' . $path,
                'mime_type' => 'image/png',
                'size' => $size,
                'width' => $info[0],
                'height' => $info[1],
            ];
        } finally {
            fclose($stream);
            imagedestroy($image);
        }
    }

    /** @return array{id:string,url:string,mime_type:string,size:int} 供受保护后台预览，缺失文件仍报故障，不能替换历史引用。 */
    public function image(string $id): array
    {
        $asset =
            $this->files->find($id) ??
            throw new BusinessException('图片资产不存在', ApiResponseConstant::NOT_FOUND);
        return [
            'id' => $asset['id'],
            'url' => $this->assetPath($asset),
            'mime_type' => $asset['mime_type'],
            'size' => (int) $asset['size'],
        ];
    }

    /**
     * 仅允许 public/storage 内已登记的 PNG/JPEG/WebP；null 表示通道选择使用文本材料。
     * 路径检查不证明图片对应真实收款账号，也不证明设备可以扫码，真实来源验证另行完成。
     *
     * @return string|null 相对站点根的受控路径，不返回服务器绝对路径。
     * @throws \RuntimeException 资产缺失、存储合同不符或符号链接越出公开目录。
     */
    public function publicImagePath(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $asset = $this->files->find($id) ?? throw new \RuntimeException('原二维码图片资产缺失');
        return $this->assetPath($asset);
    }

    /** 单一解析边界同时用于后台预览和支付材料；公开路径不接受请求指定的磁盘或服务器路径。 */
    private function assetPath(FileRecord $asset): string
    {
        $path = $asset['path'];
        if (
            $asset['storage'] !== 'local' ||
            !in_array($asset['mime_type'], ['image/png', 'image/jpeg', 'image/webp'], true) ||
            !preg_match('#^[a-zA-Z0-9_-][a-zA-Z0-9/_-]*\.(png|jpg|jpeg|webp)$#D', $path)
        ) {
            throw new \RuntimeException('二维码图片资产不符合公开存储合同');
        }
        $root = realpath($this->app->getRootPath() . 'public/storage');
        $file = $root === false ? false : realpath($root . '/' . $path);
        if (
            $root === false ||
            $file === false ||
            !is_file($file) ||
            !is_readable($file) ||
            !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/')
        ) {
            throw new \RuntimeException('二维码图片缺失或越出公开存储目录');
        }
        return '/storage/' . $path;
    }
}
