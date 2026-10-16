<?php
declare(strict_types=1);

namespace app;

use think\Service;
use app\common\util\RequestContext;
use PDO;

/**
 * ThinkPHP 应用服务：装配跨入口共享依赖，不在注册阶段访问数据库。
 *
 * 由 app/service.php 注册；控制器、服务和仓储仍通过框架容器按构造类型解析。
 */
class AppService extends Service
{
    /** 注册容器绑定；数据库连接延迟到对应能力首次被使用时。 */
    public function register(): void
    {
        // 同一次应用执行共用关联号，使响应头、JSON 和技术日志可以互相定位。
        $this->app->instance(RequestContext::class, new RequestContext());
        // 固定社区 cURL handler，确保地址绑定和传输结果钩子生效；不安装自动重试或共享 Cookie 中间件。
        $this->app->bind(
            \GuzzleHttp\ClientInterface::class,
            static function (): \GuzzleHttp\ClientInterface {
                if (
                    !extension_loaded('curl') ||
                    version_compare(curl_version()['version'], '7.59.0', '<')
                ) {
                    throw new \LogicException('HTTP 传输需要 cURL 扩展及 libcurl 7.59.0 以上版本');
                }
                return new \GuzzleHttp\Client([
                    'handler' => \GuzzleHttp\HandlerStack::create(
                        new \GuzzleHttp\Handler\CurlHandler(),
                    ),
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'cookies' => false,
                    'proxy' => '',
                    'headers' => ['User-Agent' => 'MPAY-V1/1.0'],
                ]);
            },
        );
        // 原生连接供版本迁移及只读结构诊断；复用框架连接配置，业务仓储继续使用 ORM。
        $this->app->bind(PDO::class, function (): PDO {
            return $this->app->db->connect()->connect();
        });
    }

    /** 框架在服务注册完成后调用的启动钩子；当前无额外启动动作。 */
    public function boot(): void {}
}
