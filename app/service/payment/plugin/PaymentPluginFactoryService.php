<?php
declare(strict_types=1);

namespace app\service\payment\plugin;

use app\common\constant\ApiResponseConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\ReceiptCollectorInterface;
use app\common\validation\PluginConfigValidator;
use app\exception\BusinessException;
use LogicException;
use ReflectionClass;
use think\App;
use think\Config;

/** 发现固定目录内实现接口的插件；每次执行创建独立实例，数据库类名仅用于目录展示。 */
final class PaymentPluginFactoryService
{
    /** @var array<string,class-string<PaymentInterface>>|null 当前请求发现的安装代码；常驻进程部署更新后应重启。 */
    private ?array $installed = null;

    /** @var array<class-string<PaymentInterface>,PluginDefinition> 代码定义只核验一次；不缓存带通道配置的执行实例。 */
    private array $definitions = [];

    public function __construct(
        private readonly App $app,
        private readonly Config $config,
        private readonly PluginConfigValidator $validator,
    ) {}

    /** @return list<string> 只列随本程序交付的插件，不从数据库或请求加载类名。 */
    public function codes(): array
    {
        return array_keys($this->registrations());
    }

    public function make(string $code): PaymentInterface
    {
        $class = $this->registrations()[$code] ?? null;
        if (!is_string($class)) {
            throw new BusinessException('插件未安装', ApiResponseConstant::SERVICE_UNAVAILABLE);
        }
        // 注册时已核验固定类及编码；每次创建独立实例，不复查不变的代码合同。
        return $this->app->make($class, [], true);
    }

    public function definition(string $code): PluginDefinition
    {
        $class = $this->registrations()[$code] ?? null;
        if (is_string($class) && isset($this->definitions[$class])) {
            return $this->definitions[$class];
        }
        return $this->describe($this->make($code));
    }

    public function describe(PaymentInterface $plugin): PluginDefinition
    {
        if (isset($this->definitions[$plugin::class])) {
            return $this->definitions[$plugin::class];
        }
        // 发现/刷新时即核对采集声明，避免错误类型等到后台任务执行才暴露。
        if ($plugin instanceof ReceiptCollectorInterface) {
            $plugin->isPreloginEnabled();
        }
        return $this->definitions[$plugin::class] = new PluginDefinition(
            $plugin->getCode(),
            $plugin->getVersion(),
            $plugin->getName(),
            $plugin->getEnabledPayTypes(),
            $plugin->getConfigSchema(),
            $plugin::class,
            $plugin->getDescription(),
        );
    }

    /**
     * 执行入口在此校验一次配置并初始化独立实例。init 只装配参数，外部支付动作随后调用。
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $runtime 服务生成的约定通道参数；与插件配置冲突属于程序错误。
     */
    public function initialize(string $code, array $settings, array $runtime): PaymentInterface
    {
        $plugin = $this->make($code);
        $definition = $this->describe($plugin);
        $settings = $this->validator->validate($definition->configSchema, $settings);
        if (array_intersect_key($settings, $runtime) !== []) {
            throw new LogicException('插件配置与系统运行参数冲突');
        }
        $plugin->init($settings + $runtime);
        return $plugin;
    }

    /** @return array<string,class-string<PaymentInterface>> 测试注册表只在独立测试入口生效，正式 FPM 无法由环境变量开启。 */
    private function registrations(): array
    {
        if ($this->installed !== null) {
            return $this->installed;
        }
        $plugins = [];
        // 只扫描交付代码目录的主类，不递归 SDK，也不执行数据库/请求提供的任意文件名。
        foreach (glob($this->app->getAppPath() . 'common/payment/*.php') ?: [] as $file) {
            $class = 'app\\common\\payment\\' . pathinfo($file, PATHINFO_FILENAME);
            if (
                !is_a($class, PaymentInterface::class, true) ||
                !(new ReflectionClass($class))->isInstantiable()
            ) {
                continue;
            }
            $definition = $this->describe($this->app->make($class, [], true));
            if (isset($plugins[$definition->code])) {
                throw new LogicException('插件编码重复');
            }
            $plugins[$definition->code] = $class;
        }
        $isolated =
            (PHP_SAPI === 'cli' ||
                (PHP_SAPI === 'cli-server' &&
                    defined('MPAY_ISOLATED_HTTP') &&
                    MPAY_ISOLATED_HTTP === true)) &&
            defined('MPAY_ISOLATED_TEST') &&
            MPAY_ISOLATED_TEST === true &&
            $this->config->get('mpay.environment') === 'testing';
        $tests = $isolated ? $this->config->get('payment.test_plugins', []) : [];
        if (!is_array($tests) || array_intersect_key($plugins, $tests)) {
            throw new LogicException('插件注册表格式或隔离边界无效');
        }
        // 隔离入口的显式注册也在发现阶段核验，不能绕过正式实例的接口和编码合同。
        foreach ($tests as $code => $class) {
            if (
                !is_string($class) ||
                !is_a($class, PaymentInterface::class, true) ||
                !(new ReflectionClass($class))->isInstantiable()
            ) {
                throw new LogicException('插件必须实现元信息与支付动作接口');
            }
            $definition = $this->describe($this->app->make($class, [], true));
            if ($definition->code !== $code) {
                throw new LogicException('插件编码与注册表不一致');
            }
            $plugins[$code] = $class;
        }
        ksort($plugins);
        return $this->installed = $plugins;
    }
}
