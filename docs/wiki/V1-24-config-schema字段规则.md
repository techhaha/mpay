# 插件配置声明规则

本合同用于 `PaymentInterface::getConfigSchema()` 和后台动态配置表单。插件主类直接实现接口，自行声明字段、默认值、校验及说明；服务不根据插件编码补字段或使用说明。元信息经“刷新插件”同步到插件目录表，表单只使用已同步定义。

采集调度选项 `prelogin` 属于插件代码元信息，通过 `isPreloginEnabled(): bool` 读取，默认 false；它不是 schema 字段属性，不会自动生成通道表单，也不作为可编辑参数写入数据库。接口和运行约定见[主动采集](V1-25-主动采集与会话协作.md)。

## 描述信息

`getDescription(): string` 返回插件的使用说明。可以从主类 `$paymentInfo['description']` 读取；未定义时返回空字符串。空白说明不显示说明区域。支持纯文本和换行，最多 8192 字节，不解析 HTML、Markdown 或脚本。

字段 `help` 是该字段的说明，非空才显示帮助入口。上传格式提示、平台账号填写方式等由插件自行声明。公共表单只提供控件、保存/取消、校验和执行反馈。支付结果 `presentation.pay_params` 的付款说明与插件配置说明是不同合同。

## 字段结构

schema 是有序字段列表，最多 128 项；顺序决定显示顺序。字段对象只接受下表属性，未知属性直接报插件声明错误。

| 属性 | 必需 | 规则 |
| --- | --- | --- |
| `field` | 是 | 配置 JSON 的唯一键；`^[a-z][a-z0-9_]{0,63}$` |
| `type` | 是 | 下表列出的七种类型 |
| `title` | 是 | 非空字段标题 |
| `validate` | 是 | ThinkPHP Validate 原生规则数组；规则只来自安装代码 |
| `value` | 否 | 新建/保存时字段缺失才应用的默认值；必须符合类型和规则 |
| `options` | select 必需 | 非空有序列表，最多 100 项；每项只有字符串 `value`、`label`，value 唯一 |
| `secret` | 否 | 布尔值，默认 false；用于 input/textarea，默认遮罩并提供显隐图标，不改变值或保存规则 |
| `help` | 否 | 最多 4096 字节 UTF-8 纯文本，空白不显示 |
| `placeholder` | 否 | 最多 4096 字节 UTF-8 纯文本输入提示 |
| `rows` | 否 | textarea/list 的高度，整数 2～20 |
| `visible_when` | 否 | `{field: '其他字段', value: 类型一致的值}` 或 `{field: '其他字段', in: [值1, 值2]}`；条件控制显示和必填生效范围 |

保留字段：`channel_id`、`receipt_scope_key`、`pay_type_code`、`api_config_id`。这些属于服务运行上下文，不能声明为用户设置。

每个 `upload` 字段还会在付款初始化时派生同名加 `_url` 的同源图片地址。例如 `proof` 保存资产 ID，`proof_url` 提供图片地址；同一 schema 不能再声明 `proof_url`，无论声明顺序如何。`receipt_qrcode_image_url` 同样是 `receipt_qrcode_image` 的派生字段，不是系统猜测的固定二维码参数。冲突在插件发现/刷新时直接报错。

配置提交的根节点必须是 JSON 对象；入口直接提取对象字段，再按 schema 校验原生类型，不用 JSON 编码/解码将对象转换成列表。`list` 必须提交 JSON 数组，不能用数字属性名组成的对象代替。

付款和退款初始化只补充 `channel_id`、`api_config_id`、`receipt_scope_key`、`pay_type_code` 等明确约定的运行参数，不把整行通道记录合入插件设置。插件自定义的 `name`、`id` 等字段保持原值；通道通知只补充其所需的通道、配置和范围标识。配置与运行参数同名时拒绝执行，不以覆盖方式解决冲突。

| type | 保存的 JSON 类型 | 控件与限制 |
| --- | --- | --- |
| input | string | 单行输入；secret=true 使用密码输入 |
| textarea | string | 多行文本；保留换行及 rows，secret=true 时默认遮罩 |
| number | integer | 整数输入，不接收字符串数字或小数 |
| select | string | Layui form 选择器，必须命中声明的选项值 |
| switch | boolean | Layui form 开关，false 是已提供的有效值 |
| list | string[] | 每行一项，跳过空行、保留文本两侧空格；最多 100 项，每项非空且最多 128 字节 |
| upload | string | 已登记图片的资产 ID，空字符串表示未设置；不接受路径或 URL |

上传接口统一限制 PNG/JPEG/WebP、2 MiB，并检查实际图片；这是接口的安全限制。插件需要向用户说明时，应在自己的 `help` 中写明。

## 校验、默认值和隐藏字段

- 未声明字段拒绝保存，配置 JSON 总体最多 64 KiB。
- 缺失、null、空字符串、0、false 不等价。null 不符合当前任何字段类型。0/false 继续执行规则；已提供的值先检查 JSON 类型。可选文本的空字符串沿用 ThinkPHP 原生空值语义，需要非空时明确声明 require 或条件必填规则。
- `value` 仅对缺失键补值，不覆盖已提供的空字符串、0 或 false。读取旧订单不可变快照不补当前代码的新默认值。
- `require` 在字段可见时必填；`requireWithout` 等条件规则由 ThinkPHP Validate 执行，不改写为无条件必填。
- `visible_when` 只能引用存在、非秘密且自身无显示条件的字段，避免循环。value 与 in 二选一；in 为 1～100 个类型一致的候选值，不能是空列表。比较值保持原生类型：number 用整数、switch 用布尔值、select 用字符串。先补齐所有字段默认值，再判断条件。
- 条件不满足时，服务端只停用该字段的 require/requireWithout 等必填规则；提交值仍检查类型，非空值继续执行长度、格式等原生规则。隐藏不删除已有值，不允许通过隐藏绕过输入校验。
- 隐藏字段保留输入、随表单提交，服务端照常校验；隐藏不是删除、免校验或权限控制。插件若需要“隐藏时无效”，在动作逻辑中解释，而不能依赖前端隐藏。
- 改变插件重新生成该插件字段；返回向导上一步不清空字段。新建通道只在最后一步保存数据库。

schema 声明在插件发现/定义阶段由 PluginDefinition 检查；配置值由 PluginConfigValidator 组装动态规则，调用原生 Validate::rule/check。范围、长度、正则及条件必填使用框架规则；严格 JSON 类型通过原生闭包规则补充。每次校验创建独立验证器，错误保留字段标题与具体约束，不回显提交的秘密值。配置比较不属于验证器职责。

打开编辑表单时按当前 schema 返回已保存的对应字段，不执行保存规则，也不以默认值替换现有值。现有值不符合更新后的规则时，管理员仍可打开表单修正；已删除字段不再展示或回传。保存、启用和单订单执行入口分别校验配置，冻结快照只保存原值。插件更新后先刷新目录，常驻进程重启后使用新的代码定义。

## 只读详情展示

通道详情按 schema 顺序展示参数，遵循 `visible_when`，不显示插件 `description` 或字段 `help`。详情条件只影响展示，不删除配置值；保存时的必填规则按上文执行。

- select 显示选项标签，switch 显示“是／否”，list 逐项显示；文本保持换行。
- 缺失值、空字符串、空列表统一显示“未设置”；0 和 false 是有效值。秘密字段有值时只显示“已配置”。
- upload 有值时，字段原位显示“已上传，查看图片”，点击定位到底部对应图片；底部按图片字段顺序集中预览，保持比例，最大宽高各 220px。未设置图片时只显示“未设置”。

## 秘密字段

已认证管理员的配置表单读取当前 schema 内的实际值，秘密字段也正常回填。`secret` 只控制默认遮罩：单行使用 Layui 官方 `lay-affix="eye"`；多行保留 textarea 与 rows，由相同官方显隐点缀配合文本遮罩样式展示。点击显隐不改变字段值，也不阻止编辑。

保存提交当前字段实际值，不再使用 `secret_present`、`retain_secrets`、保留复选框或“留空保留原值”约定。清空已存在文本就提交空字符串，是否合法由插件 validate 决定；不能将空值自动换回旧值。未填写的可选数字省略，按声明决定是否应用默认值，不隐式转成 0。

配置接口仅供已认证管理员访问，并保持 no-store；公开接口、日志和配置概要不展示密钥。secret 字段也可按通用 value 规则声明默认值，但源代码中不得保存真实凭证。

## 示例

```php
private array $paymentInfo = [
    'description' => "填写平台收款参数。\n账号信息可在平台管理页查看。",
    'config_schema' => [
        ['type' => 'select', 'field' => 'match_mode', 'title' => '匹配方式',
            'options' => [['value' => 'amount', 'label' => '金额'], ['value' => 'remark', 'label' => '备注']],
            'validate' => ['require', 'in' => ['amount', 'remark']], 'value' => 'amount'],
        ['type' => 'number', 'field' => 'offset_limit', 'title' => '偏移上限（分）',
            'validate' => ['require', 'between' => [0, 99]], 'value' => 99,
            'visible_when' => ['field' => 'match_mode', 'value' => 'amount'],
            'help' => '0 表示不增加金额。'],
        ['type' => 'input', 'field' => 'token', 'title' => '接口密钥',
            'validate' => ['require', 'length' => [16, 128]], 'secret' => true],
    ],
];

public function getDescription(): string { return $this->paymentInfo['description'] ?? ''; }
public function getConfigSchema(): array { return $this->paymentInfo['config_schema']; }
```

这段是字段合同示例，完整支付插件仍须实现接口的其他动作。不得将 V2 前端框架的 `props`、`inputNumber`、`fileUpload` 或对象式校验直接复制到本项目；本项目使用上面的 Layui/ThinkPHP 合同。新增控件属性应先扩充白名单、渲染和验证，再更新本文件，不允许静默忽略。

完整接口职责见[支付插件开发](V1-23-支付插件开发.md)。字段示例中的 `token` 仅为占位字段，不是真实凭证。

## 通用渲染边界

渲染器只消费 type、title、value、validate、options、secret、help、placeholder、rows、visible_when，不按插件编码、平台名或业务字段名追加控件或规则。字段顺序与 schema 一致；未定义 description/help 不生成说明。前端映射标准输入约束，完整 ThinkPHP Validate 规则仍由服务端执行，不能擅自将 PHP 规则当作 JavaScript 执行。
