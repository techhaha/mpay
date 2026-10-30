# 动态码插件维护与交付

五款插件的安装、字段、图鉴/GMP、登录采集、展示和故障排查统一见[动态码收款插件使用说明](../wiki/V1-34-动态码收款插件.md)。本文件与[文件清单](delivery.json)在两个分支保持一致，均可随公共文档发布；文档不受平台插件源码的分支限制。

## 工作目录与分支

采用同一个 Git 仓库的两个工作树（worktree），分别维护公共版和本地插件版，不再以同一目录反复切换分支作为日常流程。

| 工作目录 | 固定分支 | 维护范围 |
| --- | --- | --- |
| 项目主目录 | `v1` | 公共系统、公共插件、公共 SDK 和全部共同文档 |
| 主目录下 `runtime/worktrees/notify-private-20261028` | `codex/dynamic-receipt-plugins` | 维护五款插件入口及整个私有 `premium_sdk/` |

当前插件工作树已存在，直接在编辑器中打开它，不重复创建或克隆。两个目录共享 Git 提交、分支和历史，但各自拥有工作区和暂存区；未提交的文件修改不会互相同步，`.env`、依赖和运行缓存也不会因 Git 合并自动共享。

VS Code 提示“分支已在另一工作树签出”时，打开已列出的工作树即可。这是分支正在另一目录使用的提示，不表示代码被删除或上传；本地插件分支的“发布 Branch”按钮不能使用。

## 分支与同步

- **强制边界：`codex/dynamic-receipt-plugins` 仅留本地，两端远程均不得存在此分支。** 代理执行以根目录 `AGENTS.md` 的“Git 远程发布强制规则”为准；“同步仓库”默认仅同步公共 `v1`，不能理解为推送当前检出的分支或全部本地分支。
- `v1` 维护公共系统、公共插件、图鉴、国密 SDK、收银台及全部受版本管理的文档。
- `codex/dynamic-receipt-plugins` 保存五款 PHP 插件入口及整个 `premium_sdk/`（SDK 源码、构建脚本和本地构建产物）；不设置远程上游、不推送该分支。原五个明文平台 SDK 已迁入原生扩展，不保留回退实现。旧十个路径仍列入历史保护清单。
- 公共代码和共同文档只在公共工作树维护，按授权在 `v1` 独立提交；确认插件工作树的未提交内容已妥善处理后，再在该工作树合入 `refs/heads/v1`。不手工复制公共文件、不在两个目录重复修改同一文档，也不自动提交或合并。
- 插件开发发现公共问题时，先将公共修复独立移入 `v1`，再合回本地插件分支。只改插件专属实现时留在本地分支；配置或用法变化同时更新共同文档。
- 本地插件分支不得整体合入公共 `v1`；整体合并后删除文件仍会保留源码历史。发布只明确推送 `v1`，不使用 `git push --all` 或 `--mirror`。分支操作和提交仍按用户当次明确授权执行。

合入公共更新时，先从项目主目录核对两个工作树，再在插件目录执行合并；以下合并命令仅在已获得相应操作授权时执行：

```sh
git worktree list
git status --short --branch
git -C runtime/worktrees/notify-private-20261028 status --short --branch
git -C runtime/worktrees/notify-private-20261028 merge refs/heads/v1
```

合并后核对共同文件、专属入口及 SDK 目录和实际差异；发生冲突时逐项处理，不直接选用整棵分支覆盖。此流程只同步本地提交，不包含 Git 远程推送、Wiki 发布或测试服务器部署。两个工作树也不意味着需要同时启动连接同一业务库的 watch/work 进程。

## 文件与依赖

`delivery.json` 的 `plugins[].files` 是每款插件的一个明文入口，另须共享 `native_sdk` 中与目标环境匹配的二进制包。`private_sdk_source`、`native_sdk.private_shared_sources` 与 `private_roots` 仅是维护/保密清单，不是客户包文件。`public_support` 和 `crypto` 仅列出 V1 基础版应提供的公共前提，不能作为付费包覆盖公共代码的清单。公共基础版没有五款插件源码时仍可独立使用，文档中列出的插件需要另行安装。

国密使用公共基础版固定的 lpilp/splsm2 2.0.1 SDK；七个算法文件、原作者说明、MIT/Apache-2.0 许可、源提交与摘要随基础源码保留。GuomiCrypto 只作原随机数及编码适配，平台报文规则留在专用 SDK。根 Composer 不管理国密库或 suggest；GMP 只在实际使用 SM2 时要求。

安装包从 `v1` 制作；插件包按 `plugins[].files` 和 `native_sdk.shared_files` 明确选取，不从整个工作目录打包。包内不包含 `.env`、runtime、真实凭证、平台响应或数据库内容。卸载单款插件时只移除其 PHP 入口；其他付费插件仍使用统一扩展，不删除扩展及其 lib 目录，也不删除公共图鉴/国密或收银台支持。

## 维护检查

- 每次发布前、发布后分别使用 `git ls-remote` 核对 Gitee/origin 和 GitHub/github：`v1` 必须是预定公开提交，`refs/heads/codex/dynamic-receipt-plugins` 必须不存在。发现违规远程分支立即停止发布并报告；删除须有用户明确指令，先保留全部本地提交。
- 禁止无明确引用的推送、把当前 `HEAD` 推到 `v1`，以及 `--all`、`--mirror`、`--tags`、`--follow-tags`。只明确推送 `refs/heads/v1:refs/heads/v1`；单个发行标签须另行获得授权并验证目标历史。
- 完成公共提交并合入本地插件分支后，两分支的共同文件及文档应逐文件一致，差异为专属入口、私有 SDK 目录及根 composer.json 的一条源码开发映射；依赖清单仍与公共版一致，旧 SDK 文件删除也应符合清单。公共工作树尚未提交的修改不会被合并，不应据此判断同步遗漏，也不从插件目录复制文件覆盖。
- `v1` 的提交树及可达历史均不能包含 `private_history_paths`、当前插件入口或 `private_roots` 中的任一路径；仅检查当前文件列表不足以证明历史没有包含源码。
- 静态检查公共版时，仅要求公共依赖文件存在；另行安装的 `plugins[].files` 在公共分支缺失是正常状态，在插件分支必须齐全。
- 交付前核对插件用法、配置字段、平台前置条件、公共版本支持和国密来源摘要；开发提交、语法检查不代表真实支付验收。

## 原生 SDK 结构与构建

私有工作树的 `premium_sdk/src` 含 `shared/LicenseVerifier.php`、`shared/LicenseException.php` 和 tianque、lakala、xingyifu、fubei、yeepay 各自的 `Client.php`，共七份 PHP 源码。五款共用一个 `mpay_premium.so`，不按平台创建多个扩展。`project.yml` 只编译 `src`，不会扫描 MPAY 或 vendor。`build.sh` 在固定 Docker 镜像内编译，`package.sh` 生成带相对运行库路径的交付目录；客户无需安装 TypePHP、Docker 或 C++ 工具链。

本次固定第三方镜像 `tinywan/typephp-linux-x64` 的 v0.9.4 构建，实际取得的镜像摘要为 `sha256:9d615116ea041af964e0ed570d4c13813c3afc79d026eb865b85ac268d3bedaf`（华为镜像缓存）；其配置摘要与原镜像相同。TypePHP v0.9.4 / PHPX v2.9.3 / PHP 8.4.26 NTS / Ubuntu 22.04 / x86-64，具体源码引用和许可随 `build_info.json` 保存。它不是 Swoole 官方 Docker 镜像。升级镜像须重新验证，不使用漂移的 latest 作为发布依据。

修改阶段默认只做语法和静态检查，不随每次修改编译。固定保留构建容器 `mpay_typephp`，源码工作目录挂载到容器 `/work/premium_sdk`；容器启动只保持空闲，不自动编译，不自动删除。需要产出新版 .so 时，先确认容器挂载目录已同步预定源码，再在构建主机执行 `sh premium_sdk/build.sh`。脚本仅通过 docker exec 复用该容器，不创建/删除容器，不安装宿主机工具或修改 PHP 配置。输出 `premium_sdk/dist/php84_linux_x86_64_nts/`，构建目录与产物均不提交 Git。

容器停止后使用 `docker start mpay_typephp` 恢复；不使用时可 `docker stop mpay_typephp`，容器与绑定的源码/产物仍保留。使用 `docker exec -it mpay_typephp sh` 进入工具环境。首次准备或升级镜像才需要网络，正常编译容器关闭网络；不要把私有 SDK 上传公共镜像仓库。官方机制可查 [TypePHP 项目](https://github.com/swoole/typephp)、[PHPX 项目](https://github.com/swoole/phpx)及所用[镜像 Dockerfile](https://github.com/Tinywan/tinywan-typephp-webman/blob/main/docker/Dockerfile.linux-dynamic)。

原生 Client 通过构造参数接收现有 HttpClient；需要验证码的三款另接收 TtshituClient。PSR-7、Money、国密及现有异常在运行时由基础版 Composer 自动加载，不复制进编译目录。命名空间导入不是源码打包指令。SDK 的协议请求、签名、响应和流水规范化在扩展内；账号缓存、候选投影、最终订单匹配及生命周期仍在 PHP 插件/公共系统。没有新增公共接口、数据库字段或服务；源码通过根 Composer 标准 PSR-4 加载，客户扩展包不包含源码。

对外各 Client 提供 `API_VERSION=3`、`scope`、`authenticate`、`create`、`query`、`normalizeTransactions`；天阙另有 `detail`、`normalizeDetail`，平台签名仍按实际源码，不能把五个平台形参强行统一。`create` 分别接收原 pay 订单 $order 和当前建码进度 $meta，不再相互包装；星驿付保留现有 checkpoint 回调。所有平台 HTTP 操作仍调用同一公共 HttpClient，单次 10 秒及原账号 15 秒/插件 30 秒预算不变。易宝 CookieJar 的 `toArray` 用 `call_user_func` 调用，以避开 TypePHP 同名转换语法，并保留 Guzzle 真实 Cookie 语义。

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

此映射只属于本地插件工作树的开发配置，不新增 require/suggest，不修改依赖版本或 composer.lock。合入公共 v1 时正常合并根 composer.json，保留这条本地映射，公共依赖跟随 v1；不把私有分支反向合入公共版。正式客户使用扩展，不交付 SDK 源码或覆盖客户根 Composer 文件。

源码与扩展统一要求 PHP 8.4+，共用各插件的 GMP、图鉴、配置、授权及 API_VERSION 检查。源码调试不同时加载 mpay_premium.so，以免已有原生类使源码断点不生效；发布时只加载与运行环境匹配的扩展。私有目录源文件、构建脚本和构建资料不进入客户包。

命名依据：[PSR-1](https://www.php-fig.org/psr/psr-1/)要求类名大驼峰，[PSR-4](https://www.php-fig.org/psr/psr-4/)要求子命名空间与目录、类名与文件名的大小写一致。标准不强制 SDK 基目录使用连字符或下划线；本项目按现有小写风格和用户偏好选用 premium_sdk，保留 mpay\premium\fubei\Client → premium_sdk/src/fubei/Client.php。构建资料等本次新增名称也使用下划线，Composer/TypePHP 固定键名和第三方镜像名保持原样。[Composer PSR-4 文档](https://getcomposer.org/doc/04-schema.md#psr-4)说明基目录映射的标准配置。
