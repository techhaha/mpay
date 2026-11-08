# Android 客户端开发与构建

客户端位于本地工作区的 `clients/android`，使用独立 Git 仓库，是单一 `app` 模块的 Gradle 工程。
MPAY 后端仓库忽略整个 clients 目录，客户端源码、提交和发布单独管理；当前客户端仓库只在本地维护。
采用原生 Kotlin + Compose，界面按已确认 Demo 实现，没有把 HTML 包装成 WebView。
使用说明见 [Android 监听助手](V1-36-Android监听助手.md)。

## 工程结构

```text
clients/android/
  app/src/main/
    AndroidManifest.xml
    kotlin/cn/mpay/listener/
      ListenerApplication.kt
      AppContainer.kt
      MainActivity.kt
      ui/             页面、表单、ViewModel、共用卡片和品牌主题
      notification/   系统通知监听、恢复及运行状态通知
      forwarding/     规则匹配、固定请求发送、重试与心跳
      data/           Room、设置、加密密钥及配置备份
      platform/       已安装应用、权限及系统设置入口
    assets/rules/     内置规则 JSON
    assets/help/      离线问答
    res/             原有 MPAY 品牌转换的矢量资源
  Dockerfile
  build-apk.sh
  gradlew
  gradle/wrapper/
```

界面通过 ViewModel 观察数据和发起操作；通知处理不依赖 Activity 存活。
同一编辑流程共享草稿，不为每个子页面或弹窗建立独立服务。
依赖集中在 AppContainer 创建，不增加 DI 框架或每类一套接口/实现。

### 代码与数据约定

- Kotlin 类名使用大驼峰，成员和方法使用小驼峰；包名小写，按职责划分文件。
- Coroutine/Flow 处理异步与状态，数据库和网络不在主线程执行。
- 金额内部使用整数分，协议边界格式化为两位小数的元字符串。
- Room 保存通道、规则、转发记录三类数据，筛选使用独立列及索引，不解析 JSON 查询。
- 日志分页加载；界面状态统计与列表共用筛选条件，状态切换不改变统计基数。
- 密钥通过 Android Keystore 加密；编辑草稿的进程恢复状态不写入明文密钥。
- 自定义规则采用线性时间 RE2，不加载脚本或远程规则，不覆盖原始通知文字。
- 原始通知与冻结请求不因改名、改规则、改地址或 APK 升级而改写。
- 业务说明、字段单位、有效期和系统限制应有注释；配置和帮助随版本维护。

## 构建版本

| 项目 | 当前固定值 |
| --- | --- |
| applicationId | cn.mpay.listener |
| versionName / versionCode | 1.0.0-rc2 / 2 |
| minSdk | 26（Android 8.0） |
| compileSdk / targetSdk | 35 |
| Build Tools | 35.0.0 |
| Gradle | 8.11.1 |
| Android Gradle Plugin | 8.9.2 |
| Kotlin / Compose compiler plugin | 2.1.20 |
| Java 编译目标 | 17 |

构建镜像复用 Cirrus Labs Android SDK 35，以及已安装的 Gradle 8.11.1。
镜像使用 JDK 21 运行 Gradle，编译字节码目标为 Java 17。
SDK 编译版本不等于最低安装系统版本。

### Docker 工作目录

构建环境使用独立目录保存：

```text
source/         当前客户端源码
gradle-cache/   Gradle 与依赖缓存
android-home/   Android 命令行工具用户目录
signing/        MPAY 专属密钥和签名配置
artifacts/      APK 与校验信息
logs/           构建及静态检查日志
```

镜像固定为已验证版本，构建时挂载源码和缓存。签名目录只读挂载，
不把密钥 COPY 进镜像，不复用其他应用的签名，也不写入 Git。
Android 构建与原有 PHP SDK 容器独立。

### 构建命令

容器内使用：

```sh
gradle --no-daemon --console=plain :app:assembleRelease :app:lintRelease
```

`MPAY_SIGNING_PROPERTIES` 指向私有签名配置，包含 `storeFile`、`storePassword`、
`keyAlias`、`keyPassword`。APK 位于 `app/build/outputs/apk/release/`。
标准 Gradle Wrapper 同时保留；没有网络下载条件时，构建容器直接使用相同版本的预装 Gradle。

检查范围为编译、Android Lint、资源/Manifest、APK 签名和打包结构。
不执行 `test`、`connectedAndroidTest`、模拟器或真实付款。成功构建不代表真机验收通过。
首次下载依赖会比后续增量构建慢；构建缓存需保留并按实际磁盘空间维护。

## 收款通知协议

保持 MPAY 当前四字段表单：

```text
POST /api/pay/{通道ID}/notify
Content-Type: application/x-www-form-urlencoded
Accept: text/plain

from=<来源应用包名>
content=<JSON字符串>
timestamp=<13位毫秒时间戳字符串>
sign=<Base64 HMAC-SHA256>
```

content 示例：

```json
{
  "title": "微信收款助手",
  "msg": "微信支付收款0.50元（朋友到店）",
  "amount": "0.50",
  "package_name": "com.tencent.mm",
  "device_name": "收款手机01",
  "client": "MPAY监听助手/1.0.0-rc2"
}
```

签名算法保持：

```text
Base64(HMAC-SHA256(
    key = 通知密钥,
    data = timestamp + "\n" + 通知密钥
))
```

FormBody 只编码一次。当前签名不覆盖 content，不能将签名通过解释为通知正文或到账真实性已获证明。
来源、金额、时间窗口、订单唯一匹配和交易防重仍由接收插件与 MPAY 原支付链路负责。

amount 是客户端识别金额，服务端可采用或自行识别；当前 MPAY 继续自行从原文识别。
本客户端完整命中规则才转发，正常请求携带金额；协议允许其他实现使用 null 表示无法提供金额。
客户端不会发送虚构的订单号、第三方流水号或付款成功状态。

成功默认要求 HTTP 2xx 且正文 trim 后等于通道配置，MPAY 默认值为纯文本 `200`。
HTTP 200 不代表业务成功。网络异常、HTTP 5xx/429 才自动重试，且不延长原 30 秒窗口。
并发发送数量有限，进程恢复、手动重试和自动重试共用原始请求及总次数限制。

## 心跳约定及接收端范围

心跳直接使用通道中配置的 notify URL、from/content/timestamp/sign 四字段和原签名。
content 示例：

```json
{
  "listening": false,
  "package_name": "com.tencent.mm",
  "device_name": "收款手机01",
  "client": "MPAY监听助手/1.0.0-rc2"
}
```

不增加 type 等消息类型字段。普通收款不携带 listening；心跳按字段存在性判断，
true 和 false 都属于心跳，字符串或 null 不满足布尔约定。

心跳开关开启后约每 60 秒向已启用通道发送实际监听状态，包括暂停或监听服务尚未连接的 false。
不积压、不补发，不进入收款日志；失败不自动改变通道启停。

MPAY 手机插件复用 SmsForwarderValidator 的来源、签名及时间检查，验签后解析心跳。
notify 的 PHP 返回类型保持 array：收款返回原成功结果列表，心跳返回四字段关联数组
`{listening, package_name, device_name, client}`，不新增结果类或联合返回类型。
回调服务在收款列表校验之前识别心跳，更新运行快照并使用插件原 ACK 提前应答，
不进入订单匹配、交易确认或“忽略回调”日志。心跳使用通道通知地址，不使用单笔支付号入口。

运行快照复用 receipt 文件缓存，每个 channel_id 只覆盖最新一条，绑定当前配置 ID/版本，
配置更新后不会把旧快照当成新配置状态。保留服务端接收时间及三项客户端说明，不建数据库表。
RuntimeStatusService 统一保存/读取，原 RuntimeQueryService 合并到既有运行列表；
原列表查询仅补取通道 ID，没有新增联表、JSON 查询或逐单读取。

原“采集状态”区块改名为“通道运行状态”，不新增菜单、区块或设备注册体系。
原采集异常和计数保持，心跳超时说明读取时按 180 秒计算，不覆盖原异常，手机失败计数显示“—”。
最近活动按来源展示查单成功或有效心跳时间；详情补充设备、客户端及应用。
本轮不添加外部掉线推送、定时扫描、自动停用通道、独立 heartbeat 路由或旧地址兼容。

## 本地数据库与恢复

| 表 | 主要内容 |
| --- | --- |
| channels | 地址、加密密钥、应用、规则引用、成功正文、启停及最近心跳 |
| rules | 内置/自定义规则与版本；APK 更新仅刷新内置规则 |
| deliveries | 原始通知、金额、目标快照、固定请求、有效期、次数与结果 |

`(channelId, fingerprint)` 唯一索引防止同一通知对同一通道重复入队；
fingerprint 包含系统通知标识、发布时间和原始内容，不以金额/正文相同合并不同交易。
时间、通道、来源应用和状态列建立查询索引。

只有命中的通知才保存记录。超长或历史通知会保留明确失败/过期说明，不绕过接收端限制发送。
收到真实 ACK 后记录成功；不因为开始请求、HTTP 200 或客户端识别出金额就显示成功。
进程中断后只恢复仍在有效期内的原请求，已超时记录不重新签名。

屏幕宽度和字体以 dp/sp 及系统窗口 Insets 为准，表单和详情可滚动，键盘打开时保留操作入口。
宽屏内容居中限宽，日志标题、消息和错误说明可换行或在详情中完整查看。
各机型的窗口、权限、锁屏及后台行为由用户真机验收。

### UI 与升级约定

- 功能图标使用 lucide-static 0.468.0 的 37 个离线矢量，统一线宽，移除整包 Material 扩展图标依赖；许可随 APK 的 assets/licenses/Lucide.txt 交付。
- 统一中性色容器与青绿色交互色，避免菜单、日期及弹层回退到默认紫色；列表分隔线不额外叠加容器间距。
- 通用 SelectField 使用底部单选列表；日志筛选在一个面板中切换应用、通道和日期，来源应用取已启用通道并按包名去重。
- 日期保留 Material 日期状态与算法，只替换紧凑标题、颜色与容器；小高度窗口采用内置输入模式。
- 主导航切换无转场，子页面只滑动，不叠加透明度动画；保留系统字体缩放、安全区和键盘避让。
- 包名、Keystore 别名及签名不变，versionCode 增为 2。Room schema/version 1 和 DataStore 键不变，不使用清库或破坏性迁移。


## 1.0.0-rc2 构建记录

2026-11-08 release 编译与 Android Lint 通过。APK 为 8,898,284 字节，versionCode 为 2，
最低 API26、目标 API35，签名证书 SHA-256 与 rc1 完全一致：

```text
9a5b4bb07d0e52065e66ceebfa4cdee40edaa3810edcabca749af0f8c2097715
```

APK SHA-256：

```text
8c0854e13f09290325c1be9612e2c3e71c1e72c1130b765d046fe06ccf28fdc8
```

签名、ZIP 16KB 对齐及八个原生库的 ELF LOAD 段对齐检查通过；Room schema 与 rc1 摘要一致。
Lint 无错误，仍有 6 条电池豁免、资源目录限定及品牌矢量长度提示；编译保留一条剪贴板接口弃用提示。
没有设置 Lint 基线或屏蔽错误，没有执行独立单元、仪器、模拟器或真实交易测试。
真实覆盖升级、通知与心跳、机型布局由用户验收；安装新版时不要先卸载旧版。

## 1.0.0-rc1 构建记录

2026-11-07 使用独立 MPAY 镜像完成 release APK 编译和 Android Lint。
APK 大小 13,042,858 字节，包名、API 26 最低版本和 API 35 目标版本已从安装包读取确认。
APK v2 签名校验通过，ZIP 16 KB 对齐及全部内置原生库的 LOAD 段对齐检查通过。

SHA-256：

```text
cc90b5b8a33e56de87c1235576c3fbe7cedf618f18085bc95581f027c4c13798
```

Lint 没有错误；保留 6 条提示：用户主动请求电池优化豁免、最低版本资源目录限定，
以及四处原有品牌矢量路径长度。另有 Compose 图标和剪贴板接口弃用提示，
当前固定依赖仍支持这些接口。没有通过关闭 Lint、基线或忽略错误来放行构建。
APK 的真实通知、后台运行及多机型界面仍待人工验收。

Android 12 及以上显式关闭应用数据的云备份和设备迁移，只通过应用内导出配置，
避免把本机加密数据直接搬到另一台设备。相关平台依据：
[Android 自动备份规则](https://developer.android.com/identity/data/autobackup)、
[自适应应用图标](https://developer.android.com/develop/ui/compose/system/icon_design_adaptive)。
