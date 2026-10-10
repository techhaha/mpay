<p align="center">
  <img src="docs/wiki/assets/mpay-logo.svg" alt="MPAY" width="240">
</p>

<h1 align="center">MPAY V1</h1>
<p align="center">让个人收款更加轻松便捷</p>
<p align="center">
  <a href="https://gitee.com/technical-laohu/mpay/wikis/Home">Gitee 文档</a> ·
  <a href="https://github.com/techhaha/mpay/wiki">GitHub 文档</a> ·
  <a href="docs/wiki/V1-03-安装与环境要求.md">安装指南</a> ·
  <a href="docs/wiki/V1-15-业务网站接入.md">接口接入</a>
</p>

## 项目介绍

MPAY 是面向个人或单一收款主体的收款管理系统，将收款码、到账识别、订单管理与业务网站通知串联起来。多个网站可以使用各自的 PID 和 KEY 接入，分别管理订单与收款路由。

资金由实际收款账户接收，MPAY 负责记录与核对，不托管余额、不进行清算。系统采用 **ThinkPHP + Layui + MySQL**，后端渲染页面，静态资源本地交付，无需单独构建前端项目。

> 本 README 对应 **v1 分支的 ThinkPHP 重构版**，目前为开发版本。仓库默认分支和旧发行包可能仍是旧程序，请先核对版本。旧版、V2 与本版的部署和数据结构不可混用。

## 功能概览

| 功能 | 使用方式 |
| --- | --- |
| 网页安装 | 环境检查、数据库配置、管理员初始化，支持中断续装 |
| 多应用接入 | 每个网站独立 PID/KEY、域名范围和订单；支持密钥重置 |
| 默认收款 | 安装创建默认应用与默认组，新应用自动绑定、新通道自动入组 |
| 收款配置 | 按支付方式 → 插件 → 通道配置 → 插件参数完成新增；编辑与插件配置独立 |
| 通道路由 | 顺序轮询、权重随机、默认优先；单笔范围、每日限额/限笔、可用时段 |
| 收银台 | 按可用通道展示支付方式、二维码、金额与付款状态 |
| 订单与流水 | 交易查询、导出、最终到账证据、原订单人工核对及关闭 |
| 商户通知 | 自动重试、暂缓与放行、单次补发、逐次响应和首次确认记录 |
| 插件扩展 | 固定目录发现插件，定义同步入库，按 config_schema 自动生成配置表单 |
| 运行维护 | 两个原生 PHP CLI 入口，采集会话协调、诊断及操作审计 |

首次安装后，只需配置收款通道和应用接入信息即可使用默认路由；独立通道池、权重和时段策略可在熟悉系统后再调整。

## 内置收款插件

| 插件 | 支付方式 | 收款识别 | 使用指南 |
| --- | --- | --- | --- |
| 微信手机/PC 收款监听 | 微信支付 | 安卓通知或约定格式的 PC 消息 | [配置微信](docs/wiki/V1-11-微信手机与PC监听.md) |
| 支付宝手机收款监听 | 支付宝 | 安卓通知 | [配置支付宝](docs/wiki/V1-12-支付宝手机监听.md) |
| 支付宝账单收款 | 支付宝 | 本地 SDK 主动查询账单 | [配置账单](docs/wiki/V1-13-支付宝账单收款.md) |
| 收钱吧码牌收款 | 微信支付、支付宝 | 本地 SDK 主动查询码牌流水 | [配置收钱吧](docs/wiki/V1-14-收钱吧码牌收款.md) |

云闪付字典编码为 `unionpay`，当前尚无内置适配插件。四个监听插件均不支持接口退款；退款能力由订单原插件决定。真实设备、账号权限和平台响应需按实际接入环境联调，合成演示不等于真实支付验收。

## 界面预览

以下截图来自当前界面，使用隔离演示数据。

### 工作台

![工作台](docs/wiki/assets/dashboard.jpg)

### 应用管理与收款通道

![应用管理](docs/wiki/assets/applications.jpg)

![收款通道](docs/wiki/assets/channels.jpg)

### 分步配置与订单详情

![新增通道](docs/wiki/assets/channel-wizard.jpg)

![订单详情](docs/wiki/assets/order-detail.jpg)

## 快速开始

第一次部署可以按这条顺序阅读：[准备资料与名词](docs/wiki/新手-准备资料与名词.md) → [宝塔建站准备](docs/wiki/新手-宝塔建站准备.md) → [网页安装图文](docs/wiki/V1-03-安装与环境要求.md) → [新增通道图文](docs/wiki/V1-06-收款通道与支付方式.md) → [第一笔收款](docs/wiki/V1-04-完成第一笔收款.md)。

需要命令时使用[复制命令助手](docs/wiki/新手-复制命令助手.md)：填写自己的域名和路径，生成 PHP 检查及两个后台任务的完整命令；不需要手改示例中的占位路径。

### 环境要求

| 项目 | 最低要求 |
| --- | --- |
| PHP | 64 位 PHP 8.2，并满足锁定依赖；不限定补丁版本 |
| MySQL | 5.7.9，InnoDB / utf8mb4；5.7.44 可以使用 |
| PHP 扩展 | pdo_mysql、curl、openssl、mbstring、dom、gd、fileinfo、bcmath、ctype、iconv |
| libcurl | 7.59.0 |
| 网站 | Nginx 或 Apache，运行目录指向 public；正式接入使用 HTTPS |

不需要 Redis、消息队列或 Workerman。Web 与 CLI 均需满足依赖；具体环境和权限见[安装指南](docs/wiki/V1-03-安装与环境要求.md)。

### 安装步骤

1. 获取与本版对应的代码，准备独立空数据库和数据库账号。
2. 在项目根目录按锁文件安装依赖：

   ```sh
   composer install --no-dev --prefer-dist --no-interaction
   composer check-platform-reqs --no-dev
   ```

3. 将网站运行目录设置为 `public`，按[部署指南](docs/wiki/V1-18-部署与后台任务.md)配置路由与 HTTPS。
4. 访问 `https://你的站点/install`，完成环境检查、数据库连接和管理员设置。
5. 进入“收款配置”，新增并启用通道；在“应用管理”填写允许的业务域名，将 PID/KEY 填入业务网站。
6. 用 Supervisor 或 systemd 守护下面两个前台命令，完成[第一笔收款核对](docs/wiki/V1-04-完成第一笔收款.md)。

```sh
/path/to/php /path/to/mpay/think payment:watch --max-seconds=3600
/path/to/php /path/to/mpay/think payment:work --max-seconds=3600
```

`watch` 负责主动流水采集；`work` 负责商户通知与订单维护。命令定期退出后应由守护工具自动重启，不再增加第三个日常入口。

## 业务网站接入

对外协议为易支付 V1：

| 入口 | 用途 |
| --- | --- |
| `/submit.php` | 页面下单并跳转收银台 |
| `/mapi.php` | API 下单并获得付款信息 |
| `/api.php` | 应用身份认证、订单查询及插件支持的动作 |

按应用 PID/KEY 签名。业务网站接收付款通知后，需要验签、核对订单和金额、幂等处理，再返回纯文本 `success`。不要用浏览器回跳作为履约依据。

完整参数与可下载示例见[接入指南](docs/wiki/V1-15-业务网站接入.md)、[接口参考](docs/wiki/V1-16-易支付V1接口参考.md)及[通知排障](docs/wiki/V1-17-通知验签与联调排障.md)。

## 运行逻辑与扩展

![支付链路](docs/wiki/assets/payment-flow.png)

Controller 处理入口，Validator 校验输入，Service 编排交易，Repository/Model 负责持久化。插件独立解释平台协议、声明配置、定位收款订单；SDK 封装平台请求与签名。事务统一确认订单、最终流水和商户通知责任，外部请求在事务外执行。

```text
app/
  http/                    HTTP 控制器、中间件及输入校验
  service/                 接入、选路、支付、通知与后台业务
  repository/              查询、锁与持久化
  model/                   表映射与关联
  common/payment/          四个支付插件主类
  common/interface/        支付与采集接口
  common/sdk/              平台 SDK
  command/                 安装、诊断、后台任务命令
config/                    应用配置
database/                  SQL 与版本迁移
public/                    网站入口、Layui、主题及品牌素材
view/                      安装、后台与收银台模板
docs/wiki/                 对外文档、示例和同步工具
```

从[开发环境与目录](docs/wiki/V1-21-开发环境与目录结构.md)开始，再阅读[支付链路](docs/wiki/V1-22-支付链路与数据约定.md)、[插件开发](docs/wiki/V1-23-支付插件开发.md)、[config_schema](docs/wiki/V1-24-config-schema字段规则.md)和[数据字典](docs/wiki/V1-27-数据库表与字段字典.md)。

## 文档与维护

- [完整文档目录](docs/wiki/Home.md)：安装、运营、插件、接口、运维、二次开发六个部分。
- [备份恢复与域名迁移](docs/wiki/V1-19-备份恢复与域名迁移.md)：保存数据库、配置和上传材料的完整对应关系。
- [版本更新](docs/wiki/V1-28-版本更新与文档维护.md)：仅按目标版本明确提供的升级路径操作。
- [Wiki 同步说明](docs/wiki/tools/README.md)：docs/wiki 为唯一正文源，远程 Wiki 为发布副本。

## 反馈与贡献

可在 [Gitee Issues](https://gitee.com/technical-laohu/mpay/issues) 或 [GitHub Issues](https://github.com/techhaha/mpay/issues) 反馈问题。请附版本、复现步骤、请求编号和脱敏错误；不要公开 KEY、平台密码、数据库备份或完整平台报文。

修改插件协议、配置字段、状态或页面操作时，请同步对应文档和示例。新增插件应实现当前接口，遵守流水幂等、金额单位与未知结果约定，不依赖其他项目的本机文件。

## 开源组件与许可

框架和第三方组件的原有版权及许可证随源码保留。当前仓库的许可证文件见 [LICENSE.txt](LICENSE.txt)；ThinkPHP、Layui 及各 Composer 依赖仍适用其各自许可证。
