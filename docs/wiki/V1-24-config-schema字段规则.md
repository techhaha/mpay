# 插件配置声明规则

本合同用于 `PaymentInterface::getConfigSchema()` 和后台动态配置表单。插件主类直接实现接口，自行声明字段、默认值、校验及说明；服务不根据插件编码补字段或使用说明。元信息经“刷新插件”同步到插件目录表，表单只使用已同步定义。

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
| `secret` | 否 | 布尔值，默认 false；秘密字段不得有默认值 |
| `help` | 否 | 最多 4096 字节 UTF-8 纯文本，空白不显示 |
| `placeholder` | 否 | 最多 4096 字节 UTF-8 纯文本输入提示 |
| `rows` | 否 | textarea/list 的高度，整数 2～20 |
| `visible_when` | 否 | `{field: '其他字段', value: 类型一致的值}`；只控制显示 |

保留字段：`channel_id`、`receipt_scope_key`、`receipt_qrcode_image_url`、`pay_type_code`、`configuration_id`、`api_config_id`、`collection_configuration_ids`。这些属于服务运行上下文，不能声明为用户设置。

| type | 保存的 JSON 类型 | 控件与限制 |
| --- | --- | --- |
| input | string | 单行输入；secret=true 使用密码输入 |
| textarea | string | 多行文本；秘密字段只返回存在标记 |
| number | integer | 整数输入，不接收字符串数字或小数 |
| select | string | Layui form 选择器，必须命中声明的选项值 |
| switch | boolean | Layui form 开关，false 是已提供的有效值 |
| list | string[] | 每行一项；最多 100 项，每项非空且最多 128 字节 |
| upload | string | 已登记图片的资产 ID，空字符串表示未设置；不接受路径或 URL |

上传接口统一限制 PNG/JPEG/WebP、2 MiB，并检查实际图片；这是接口的安全限制。插件需要向用户说明时，应在自己的 `help` 中写明。

## 校验、默认值和隐藏字段

- 未声明字段拒绝保存，配置 JSON 总体最多 64 KiB。
- 缺失、null、空字符串、0、false 不等价。null 不符合当前任何字段类型。0/false/已提供的空字符串仍执行规则。
- `value` 仅对缺失键补值，不覆盖已提供的空字符串、0 或 false。读取旧订单不可变快照不补当前代码的新默认值。
- `require` 无条件必填；`requireWithout` 等条件规则由 ThinkPHP Validate 执行，不改写为无条件必填。
- `visible_when` 只能引用存在、非秘密且自身无显示条件的字段，避免循环。比较值保持原生类型：number 用整数、switch 用布尔值、select 用字符串。
- 隐藏字段保留输入、随表单提交，服务端照常校验；隐藏不是删除、免校验或权限控制。插件若需要“隐藏时无效”，在动作逻辑中解释，而不能依赖前端隐藏。
- 改变插件重新生成该插件字段；返回向导上一步不清空字段。新建通道只在最后一步保存数据库。

## 秘密字段

后台读取仅返回 `secret_present`，不返回原值或伪掩码。编辑时保持“修改已保存的值”未选中，提交到 `retain_secrets` 保留原值；勾选后提交新值。

清空可选秘密时提交空字符串，且声明必须允许空字符串；必填秘密不能清空。保留与替换同一个字段同时提交会被拒绝。日志不记录参数正文。

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
