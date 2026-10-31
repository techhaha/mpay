# MPAY · 码支付 V1

让个人收款更加轻松便捷。

MPAY 是基于 ThinkPHP 与 Layui 的收款管理程序，提供多应用 PID/KEY、收款通道、订单核对、收银台和商户通知，对外支持易支付 V1 接口。内置微信手机/PC、支付宝手机、支付宝账单、收钱吧码牌和中行/建行/工行银行卡监听，另有易支付 V1 平台插件。银行卡使用微信监听工具读取对应银行公众号的完整动账消息。

## 安装要求

- PHP 8.2 及以上、64 位；Web 与命令行 PHP 均需满足要求。
- MySQL 5.7.9 及以上，使用独立空数据库；MySQL 5.7.44 满足要求。
- PHP 扩展：PDO MySQL、cURL、OpenSSL、mbstring、DOM、GD、fileinfo、BCMath、ctype、iconv；libcurl 7.59.0 及以上。
- Nginx 或 Apache，支持 HTTP 或 HTTPS 站点。无需 Redis、消息队列或 Workerman。

包内另含天阙、拉卡拉、星驿付、付呗和易宝五款免输入插件的 PHP 入口；使用其收费功能需要另装共享 MPAY Premium 扩展并填写授权码，基础包不附收费二进制。当前扩展 1.2.2 / SDK API 3 配套入口 1.3.0，原生包限定 Linux x86-64 / PHP 8.4 NTS，网站和原两个守护任务均需匹配。拉卡拉、星驿付、易宝另配图鉴，后两款还需 PHP GMP。安装、配置、升级与故障处理见[免输入插件完整教程](../V1-34-动态码收款插件.md)，启用插件不需要重新安装数据库。

GMP 按插件需要启用，不影响基础版安装；需要的插件会在配置说明中提示，国密 SDK 已由 V1 基础版提供，不要求客户另外安装国密 Composer 包。Ubuntu/Debian 的宝塔安装 GMP 失败且提示缺少 GMP 库或头文件时，在宝塔左侧“终端”执行 `sudo apt-get install libgmp-dev`，再回 PHP 扩展页面重新安装 GMP。使用这类插件前，分别确认网站及监听 PHP 的扩展加载情况；调整后重启相应 PHP 服务和任务。

## 安装步骤

1. 解压安装包到站点目录。包内已含生产依赖，无需在服务器执行 Composer。
2. 将网站运行目录设置为 `public/`，配置访问地址和伪静态。宝塔选择“PHP 项目”，站点 PHP 选 8.2 及以上；在“网站目录”保存运行目录 `/public`，在“伪静态”用以下规则整体替换编辑框中的旧内容并保存：

   ```nginx
   if (!-e $request_filename) {
       rewrite ^(.*)$ /index.php?s=$1 last;
   }
   ```

   片段位于 Nginx 的 server 上下文，**不要包裹 `location /`**；不同版本的 `thinkphp` 预置模板可能不同，以此片段为准。保留面板管理的 PHP-FPM 配置及主配置中的敏感目录保护。`/submit.php`、`/mapi.php`、`/api.php` 是没有对应磁盘文件的程序路由，上述规则同样将它们交给 `index.php`，保留原路径和查询参数，不需要创建同名文件。不要将完整站点配置粘贴进伪静态输入框。Apache 使用包内 `public/.htaccess`，需启用 rewrite 和相应 AllowOverride。禁止上传目录执行 PHP。完整图文见 [宝塔部署教程](https://gitee.com/technical-laohu/mpay/wikis/新手-宝塔建站准备)。
3. 创建 MPAY 专用数据库及其账号，首次安装使用空库。账号需具备建表、索引、外键和数据读写权限；覆盖重装还需 DROP 权限。安装器不创建或删除整个数据库；已有 MPAY 数据可明确选择“覆盖并重新安装”，输入库名确认后清除全部 MPAY 表及原配置、交易，再全新初始化。
4. 确保 PHP 进程可写项目根目录、`runtime/` 和 `public/storage/`。访问 `https://你的域名/install`，完成环境检查、数据库连接和管理员设置。
5. 安装完成后，限制 `.env` 仅供站点运行账号读取，并收紧项目根目录写权限；`runtime/` 和 `public/storage/` 继续保留运行所需写权限。安装入口会自动关闭。
6. 在进程管理工具中分别守护以下两个命令，工作目录设置为项目目录。宝塔中两个任务的启动用户均选择 `www`，与网站 PHP 运行用户保持一致，每个任务的进程数量设为 `1`：

   ```sh
   php think payment:watch
   php think payment:work
   ```

   `watch` 负责主动查单，`work` 负责商户通知和订单维护。默认持续运行；退出后由 Supervisor、systemd 或服务器面板自动重启。PHP 不在 PATH 时填写实际 PHP 可执行文件路径。任务启动失败时查看[后台任务排查](https://gitee.com/technical-laohu/mpay/wikis/V1-18-部署与后台任务#常见启动结果)。

7. 打开 `/admin/login`，填写安装时设置的用户名、密码和当前图片中的 4 位验证码。看不清时点击图片更换，提示失效时重新获取；遇到频率限制按页面提示等待。登录后进入“收款配置”新增通道并填写插件参数。在“更多 → 测试”发起一笔付款，核对到账和后台状态；再将应用的 PID/KEY 填入业务网站。

首次安装自动创建默认应用、默认轮询组及绑定。后续新增应用、通道也会自动接入默认路由。付款测试应由真实到账验证，二维码生成或浏览器跳转不代表收款成功。

HTTP 和 HTTPS 都可以访问，不需要填写 `COOKIE_SECURE` 或固定站点网址。两种协议共用当前主机的 `mpay_session` 和文件会话，程序不按协议改写 Cookie、不比较浏览器 Origin 与服务器地址。反向代理需正确传递访问主机和协议，以便框架生成可用链接；程序没有代理专用模式。

## 文件说明

```text
app/                  程序代码及内置插件
config/               程序配置
database/migrations/  一份最终新装建表定义
database/seeds/       初装支付方式及品牌图片声明
public/               网站公开入口、前端资源及空 storage 目录
public/docs/          本地易支付 V1 接口文档及文本示例
route/                路由
runtime/              空运行目录，首次运行后生成缓存、日志和安装锁
vendor/               已安装的生产依赖及其许可证
view/                 页面模板
composer.json
composer.lock
think
LICENSE.txt
README.md
```

安装包不含站点配置、账号密码、演示订单、缓存和上传图片。安装过程中会生成 `.env` 和安装锁，二者不可随意删除。数据库只有一份新装基线；安装进度表用于故障后续装，不能删除。中断后使用同一安装包和原数据库继续安装，不需要清库。

此包支持全新安装及清除数据的覆盖重装，不执行保留旧数据的版本升级。覆盖前备份、停止业务和两个后台任务，按[网页安装指南](../V1-03-安装与环境要求.md#覆盖并重新安装)操作；成功后旧后台登录态失效，上传和日志保留。恢复站点应使用与备份数据库匹配的程序版本，并恢复数据库、`.env`、`runtime/install.lock` 和 `public/storage/`，不要选择覆盖安装。

## 使用文档

首页的“接入文档”打开本站 `/docs/`，无需登录即可查看易支付 V1 下单、查询、通知验签及退款说明，复制本站网关和代码示例。页面已包含在安装包中，不需要额外安装 Node.js 或文档服务。

[Gitee 使用手册](https://gitee.com/technical-laohu/mpay/wikis/Home) · [GitHub 使用手册](https://github.com/techhaha/mpay/wiki)

具体平台所需账号、权限和监听客户端见对应插件说明。开源许可证见 `LICENSE.txt`；第三方依赖和前端组件保留各自许可证。
