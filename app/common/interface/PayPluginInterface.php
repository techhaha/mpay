<?php
declare(strict_types=1);

namespace app\common\interface;

/** 插件定义与配置装配合同；订单和请求环境通过具体动作参数传递。 */
interface PayPluginInterface
{
    /** @param array<string,mixed> $config 已保存的插件配置；不包含服务运行参数。 */
    public function init(array $config): void;

    public function getCode(): string;
    public function getName(): string;
    public function getVersion(): string;
    public function getDescription(): string;

    /** @return list<string> 支持的支付方式编码。 */
    public function getEnabledPayTypes(): array;

    /** @return list<array<string,mixed>> 配置表单定义，完整字段合同见 config-schema 文档。 */
    public function getConfigSchema(): array;
}
