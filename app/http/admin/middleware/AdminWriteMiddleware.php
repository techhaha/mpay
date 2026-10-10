<?php
declare(strict_types=1);

namespace app\http\admin\middleware;

use app\common\constant\ApiResponseConstant;
use app\common\util\SiteUrl;
use app\exception\BusinessException;
use app\service\file\FileAssetService;
use think\Request;
use think\Response;

/** 后台写接口共用的来源和载荷边界；登录也必须使用 Session CSRF，不能作为匿名写入豁免。 */
final class AdminWriteMiddleware
{
    public function __construct(private readonly SiteUrl $site)
    {
    }

    /**
     * @param \Closure(Request):Response $next 随后执行独立的 CSRF 和业务校验。
     * @param string $profile 由路由固定传入；配置 JSON 为 128 KiB，单图片 multipart 为 2 MiB 加 64 KiB 封装，其他 JSON 为 8 KiB。
     */
    public function handle(Request $request, \Closure $next, string $profile = 'default'): Response
    {
        $maximum = match ($profile) {
            'default' => 8192, 'configuration' => 131072, 'image' => FileAssetService::MAX_UPLOAD_BYTES + 65536,
            default => throw new \LogicException('未知后台正文限制配置'),
        };
        $origin = $request->header('Origin', '');
        if ($request->header('Sec-Fetch-Site', '') === 'cross-site'
            || ($origin !== '' && $origin !== $this->site->origin())) {
            throw new BusinessException('请在本站后台提交操作', ApiResponseConstant::FORBIDDEN);
        }
        $contentType = strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0]));
        if ($profile === 'image') {
            // PHP 已解析 multipart，不能用 getInput 的空字符串冒充已检查大小；站点同样须设置正文/上传上限。
            $length = (string) $request->server('CONTENT_LENGTH', '');
            if ($contentType !== 'multipart/form-data' || !preg_match('/^[1-9][0-9]{0,7}$/D', $length) || (int) $length > $maximum) {
                throw new BusinessException('图片上传正文格式或大小无效', ApiResponseConstant::BAD_REQUEST);
            }
            return $next($request);
        }
        if ($contentType !== 'application/json' || strlen($request->getInput()) > $maximum) {
            throw new BusinessException('后台操作必须使用受限 JSON 正文', ApiResponseConstant::BAD_REQUEST);
        }
        return $next($request);
    }
}
