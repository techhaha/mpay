# 动态码插件维护与交付

五款插件的安装、配置、图鉴/GMP、采集和故障排查见[动态码收款插件使用说明](../wiki/V1-34-动态码收款插件.md)。仅 `premium_sdk/` 是当前私有内容；PHP 插件入口、公共支持、根 Composer 配置及全部文档均在两个分支保持一致，可以随 `v1` 发布。

## 工作目录与分支

采用同一个 Git 仓库的两个工作树，不以同一目录反复切换分支作为日常流程。

| 工作目录 | 固定分支 | 维护范围 |
| --- | --- | --- |
| 项目主目录 | `v1` | 除 `premium_sdk/` 外的项目源码与文档，包括五款 PHP 入口和根 Composer 配置 |
| 主目录下 `runtime/worktrees/notify-private-20261028` | `codex/dynamic-receipt-plugins` | 与 `v1` 相同的公共内容，另维护私有 `premium_sdk/` |

两个目录共享 Git 提交、分支和历史，工作区与暂存区独立；未提交修改不会自动同步。现有工作树直接在编辑器打开，不重复创建或克隆。VS Code 提示分支已在另一工作树签出时，打开该工作树即可，不表示代码丢失或已上传。

## 分支与同步

- **`codex/dynamic-receipt-plugins` 仅留本地，不设远程上游，也不推送到任何远程仓库。** “同步仓库”默认仅发布公共 `v1`，不得使用插件分支的“发布 Branch”。具体发布检查以根 `AGENTS.md` 为准。
- 五款 PHP 入口是公开适配层，在 `v1` 维护；插件开发中改到 `premium_sdk/` 之外的内容也应回到公共源，不保留永久分支差异。根 Composer 的 `mpay\premium\` 映射同样属于共同配置，声明映射不会包含 SDK 源码或新增依赖。
- 日常共同代码和文档在公共工作树维护，按授权独立提交后单向合入插件分支。禁止将插件分支整体反向合入 `v1`，避免私有 SDK 及其历史进入公共提交链。
- 用户明确要求同步未提交内容时，先备份并逐文件核对双方改动，再对齐工作区；不能覆盖独有修改或借同步自动提交。工作区对齐不移动分支 HEAD，不能报告成 Git 提交已经合并。
- `.env`、vendor、runtime、数据库和 Git 元数据不属于项目源码同步范围。本地代理交接资料仍以公共根目录为维护入口，不重复维护两套 AGENTS.md。

已获提交及合并授权时，先检查两个工作树，再执行明确分支合并：

```sh
git worktree list
git status --short --branch
git -C runtime/worktrees/notify-private-20261028 status --short --branch
git -C runtime/worktrees/notify-private-20261028 merge refs/heads/v1
```

合并或工作区同步后，核对 `premium_sdk/` 之外的受版本管理项目文件全部一致。冲突逐项处理，不直接选择整棵分支覆盖。同步不包含远程推送、Wiki 发布或测试服部署，也不要求同时启动两套后台任务。

## 文件与依赖

`delivery.json` 的 `plugins[].files` 是随 `v1` 提供的明文入口，不再属于保密清单。使用收费功能仍须安装 `native_sdk` 中与目标环境匹配的二进制包并填写授权码。`private_sdk_source`、`native_sdk.private_shared_sources` 和 `private_roots` 只描述私有 SDK，不是客户源码包文件。`public_support` 和 `crypto` 由基础版提供；未安装收费扩展仍可读取插件说明，实际使用由入口既有环境检查提示，不影响免费插件。

国密使用公共基础版固定的 lpilp/splsm2 2.0.1 SDK，保留七个算法文件、来源摘要和 MIT/Apache-2.0 许可；根 Composer 不管理国密库或 suggest。GMP 只在实际使用 SM2 时要求。

基础安装包从 `v1` 制作，包含五款 PHP 入口。收费包按 `native_sdk.shared_files` 交付匹配的扩展，不从整个私有工作目录打包，不包含 `.env`、runtime、真实凭证、响应或数据库。旧明文平台 SDK 不恢复；卸载单款入口时不删除其他插件仍使用的扩展、公共图鉴或国密支持。

## 维护检查

- 每次发布前后用 `git ls-remote` 核对两端公共 `v1` 为预定提交、本地插件分支不存在。发现违规远程分支立即停止发布并报告；删除仍须用户明确授权并先保留本地提交。
- 只明确推送 `refs/heads/v1:refs/heads/v1`，禁止推送当前 HEAD、全部分支或全部标签。Wiki 仅从已核对的公共 docs/wiki 发布。
- 同步后除 `premium_sdk/` 外的项目源码和文档应一致，包括五款入口、composer.json 和 composer.lock；分别记录工作区对齐与 Git 提交合并状态。
- 公共提交树和可达历史不得包含 `private_roots`。`private_history_paths` 只保留已退役的核心 SDK 路径，防止旧核心代码经历史泄露；五款公开入口已从该清单移除。
- `.gitignore` 统一忽略 `/premium_sdk/`，避免公共工作区误加源码。私有分支已跟踪文件仍正常显示修改；新增 SDK 文件只在确认当前为本地插件分支后，按明确文件路径 `git add -f`，不批量强制添加目录或仓库。
- 两分支都须包含五款入口及公共依赖；只有私有开发工作树包含 SDK 源码。基础版最低 PHP 版本不变，收费功能仍按插件说明要求 PHP 8.4+、匹配扩展及授权。

## 原生 SDK 结构与构建

私有工作树的 `premium_sdk/src` 含 `shared/LicenseVerifier.php`、`shared/LicenseException.php` 和 tianque、lakala、xingyifu、fubei、yeepay 各自的 `Client.php`，共七份 PHP 源码。五款共用一个 `mpay_premium.so`，不按平台创建多个扩展。`project.yml` 只编译 `src`，不会扫描 MPAY 或 vendor。`build.sh` 在固定 Docker 镜像内编译，`package.sh` 生成带相对运行库路径的交付目录；客户无需安装 TypePHP、Docker 或 C++ 工具链。

本次固定第三方镜像 `tinywan/typephp-linux-x64` 的 v0.9.4 构建，实际取得的镜像摘要为 `sha256:9d615116ea041af964e0ed570d4c13813c3afc79d026eb865b85ac268d3bedaf`（华为镜像缓存）；其配置摘要与原镜像相同。TypePHP v0.9.4 / PHPX v2.9.3 / PHP 8.4.26 NTS / Ubuntu 22.04 / x86-64，具体源码引用和许可随 `build_info.json` 保存。它不是 Swoole 官方 Docker 镜像。升级镜像须重新验证，不使用漂移的 latest 作为发布依据。

修改阶段默认只做语法和静态检查，不随每次修改编译。固定保留构建容器 `mpay_typephp`，源码工作目录挂载到容器 `/work/premium_sdk`；容器启动只保持空闲，不自动编译，不自动删除。需要产出新版 .so 时，先确认容器挂载目录已同步预定源码，再在构建主机执行 `sh premium_sdk/build.sh`。脚本仅通过 docker exec 复用该容器，不创建/删除容器，不安装宿主机工具或修改 PHP 配置。输出 `premium_sdk/dist/php84_linux_x86_64_nts/`，构建目录与产物均不提交 Git。

容器停止后使用 `docker start mpay_typephp` 恢复；不使用时可 `docker stop mpay_typephp`，容器与绑定的源码/产物仍保留。使用 `docker exec -it mpay_typephp sh` 进入工具环境。首次准备或升级镜像才需要网络，正常编译容器关闭网络；不要把私有 SDK 上传公共镜像仓库。官方机制可查 [TypePHP 项目](https://github.com/swoole/typephp)、[PHPX 项目](https://github.com/swoole/phpx)及所用[镜像 Dockerfile](https://github.com/Tinywan/tinywan-typephp-webman/blob/main/docker/Dockerfile.linux-dynamic)。

原生 Client 通过构造参数接收现有 HttpClient；需要验证码的三款另接收 TtshituClient。PSR-7、Money、国密及现有异常在运行时由基础版 Composer 自动加载，不复制进编译目录。命名空间导入不是源码打包指令。SDK 的协议请求、签名、响应和流水规范化在扩展内；账号缓存、候选投影、最终订单匹配及生命周期仍在 PHP 插件/公共系统。没有新增公共接口、数据库字段或服务；源码通过根 Composer 标准 PSR-4 加载，客户扩展包不包含源码。

对外各 Client 提供 `API_VERSION=3`、`scope`、`authenticate`、`create`、`query`、`normalizeTransactions`；天阙另有 `detail`、`normalizeDetail`，平台签名仍按实际源码，不能把五个平台形参强行统一。`create` 分别接收原 pay 订单 $order 和当前建码进度 $meta，不再相互包装；星驿付保留现有 checkpoint 回调。所有平台 HTTP 操作仍调用同一公共 HttpClient，单次 10 秒及原账号 15 秒/插件 30 秒预算不变。易宝 CookieJar 的 `toArray` 用 `call_user_func` 调用，以避开 TypePHP 同名转换语法，并保留 Guzzle 真实 Cookie 语义。

平台 JSON 直接交给 `json_decode(..., JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)`，不再用正则把整份响应的数字改成字符串。字符串编号原样保留，超出 PHP 整数范围的整数由原生选项保留为字符串；普通整数、小数和布尔值保持原生类型。数字字段遵循对应平台协议，不猜测或修正平台字段类型，通用编号读取仍不接受 float。

| 平台 | 实际收款金额字段 | 单位及处理 |
| --- | --- | --- |
| 天阙 | 列表及详情 `amt` | 元金额读取后转整数分 |
| 拉卡拉 | `tranOrdAmt` | 元金额读取后转整数分 |
| 星驿付 | `amount` | 整数分直接核验和使用 |
| 付呗 | `receivedAmount` | 元金额读取后转整数分 |
| 易宝 | `realPayAmount` | 元金额读取后转整数分 |

四款元金额字段接受平台的 JSON 数字或文本。原生解码得到的小数只在金额读取处转为十进制文本，再执行原有尾随零、分精度、正数和金额上限检查，交由 `Money::parse()` 转整数分；不做浮点乘 100、四舍五入或截断收款金额。这是对已约定元金额字段的转换，不是恢复任意小数的原始精度。状态码、列表数量、验证码和其他字段不经过此转换。响应关闭、平台错误分类、金额/归属/窗口/幂等校验保持原规则。

新增平台时只加对应 Client 和 PHP 入口，开发阶段使用源码；需要发布新版扩展时再统一编译。旧 API 保持时无需改旧入口。破坏 API 时同时升级 API_VERSION 和相应插件，整包发布/回滚，不能单独替换来源不同的 libphpx。构建器 GPL 与随包 PHPX Apache-2.0 的来源和许可分别记录；保留运行库许可与 NOTICE，不打包编译器、vendor 或生成的 C++。

## 建码订单参数（SDK API 3，待构建）

订单上下文和建码进度是两个独立参数，五款入口直接调用：

```php
$created = $this->sdk()->create($this->configuration, $session, $order, $meta, $deadline);
```

四款普通创建统一为 create(array $config, array &$session, array $order, array $meta, DateTimeImmutable $deadline): array；星驿付最后保留 callable $checkpoint。

- $order：框架提供的原 pay 订单上下文，包含本站回调地址等信息。
- $meta：原 prepare 返回的当前建码进度，包含金额、平台单号和处理阶段。

SDK 直接使用 $meta，不从 order.extra.dynamic_receipt 读取进度；调用方不为传参改写 $order。数据库原有 dynamic_receipt 保存位置、事务锁、执行权检查、阶段回写、结果及授权时机保持不变。不新增包装对象、辅助接口或旧签名兼容分支。

当前源码目标为插件入口 1.3.0、扩展 1.2.0 / API_VERSION=3；本轮不编译。现存扩展 1.1.0 / API 2 是上一轮产物，不能与当前入口混用，现有 init 版本检查会拒绝。待明确要求产出新版扩展时再统一构建；源码模式同样使用 API 3，PHP 8.4 及商业授权要求不变。

订单数组仍由调用方提供；将参数合并、拆开或隐藏字段名都不是防伪造措施。继续使用用户确认的 V2 通用离线授权，不新增在线认证、主机猜测或混淆机制。

## 当前验证范围

此前 SDK API 2 阶段曾通过源码/native 各 45 项隔离检查，覆盖五平台创建、订单内域名取值、授权失败前不外呼、结果不含完整订单、星驿付阶段保存、Cookie 和流水转换；转换摘要一致，真实平台请求为零。插件元信息/init 及 PHP 8.4 CLI/FPM 独立加载核对通过，修复了星驿付和易宝重复 description 覆盖图鉴/GMP 说明的问题。未访问业务数据库、部署业务站点或重启业务服务；SM2 实际登录及五平台实付仍未验收。

当前 API 3 的参数分离仅做语法与静态检查，没有重新编译或运行上述隔离场景；历史验证不代表当前二进制已生成。固定 mpay_typephp 容器保留，只在明确需要新版扩展时构建。此前 API 1/2 产物不与当前入口混用。

公共文档先在 v1 提交，再单向合入本地插件分支；具体提交、部署及验收结果记录在本地维护资料，不以本页用法说明代替服务器实际状态。不能用复制公共工作区代替已提交同步。

## 源码开发与 IDE

使用根 composer.json 的标准 PSR-4 映射，不设独立 autoload.php，不设嵌套 Composer 配置，不修改 ThinkPHP 启动入口：

```json
"autoload": {
    "psr-4": {
        "app\\": "app/",
        "mpay\\premium\\": "premium_sdk/src/"
    }
}
```

在本地插件工作树准备好项目原有 vendor 后执行 `composer dump-autoload --no-scripts --no-plugins`。ThinkPHP 原有 vendor/autoload.php 即可按映射加载 SDK；无需额外 require、auto_prepend_file 或模式开关。IDE 使用同一根 Composer 声明和工作树源码进行补全与跳转。

若服务器的 CLI 配置阻止 Composer 执行，先保持网站安全设置；可在可信环境按服务器实际生产依赖生成加载文件再部署。必须核对 lock、已安装依赖和 ClassLoader 一致，不能直接用包含额外开发依赖的加载文件覆盖生产环境。

此映射在两个分支保持一致，不新增 require/suggest，不修改依赖版本或 composer.lock。公共版没有 premium_sdk/src/ 时不会自动获得 SDK；客户安装扩展后使用已注册的同名原生类。正式客户不接收 SDK 源码，也不需要为收费插件修改根 Composer 配置。

源码与扩展统一要求 PHP 8.4+，共用各插件的 GMP、图鉴、配置、授权及 API_VERSION 检查。源码调试不同时加载 mpay_premium.so，以免已有原生类使源码断点不生效；发布时只加载与运行环境匹配的扩展。私有目录源文件、构建脚本和构建资料不进入客户包。

命名依据：[PSR-1](https://www.php-fig.org/psr/psr-1/)要求类名大驼峰，[PSR-4](https://www.php-fig.org/psr/psr-4/)要求子命名空间与目录、类名与文件名的大小写一致。标准不强制 SDK 基目录使用连字符或下划线；本项目按现有小写风格和用户偏好选用 premium_sdk，保留 mpay\premium\fubei\Client → premium_sdk/src/fubei/Client.php。构建资料等本次新增名称也使用下划线，Composer/TypePHP 固定键名和第三方镜像名保持原样。[Composer PSR-4 文档](https://getcomposer.org/doc/04-schema.md#psr-4)说明基目录映射的标准配置。
