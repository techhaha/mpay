# MPAY Docker 容器化部署指南

本文档介绍如何使用 Docker 与 Docker Compose 部署 **MPAY 码支付系统**。所有部署文件均采用**零耦合设计**，不污染原有项目业务代码，遵循生产环境最佳实践。

---

## 目录
- [一、部署方案选型](#一部署方案选型)
- [二、快速上手（3分钟起步）](#二快速上手3分钟起步)
  - [方式 1：Web 网页向导安装（推荐）](#方式-1web-网页向导安装推荐)
  - [方式 2：全自动静默一键安装](#方式-2全自动静默一键安装)
- [三、已有外部基础设施接入](#三已有外部基础设施接入)
  - [1. 接入外部已有 MySQL（云 RDS 或宿主机数据库）](#1-接入外部已有-mysql云-rds-或宿主机数据库)
  - [2. 接入外部已有 Nginx / 宝塔 / 1Panel](#2-接入外部已有-nginx--宝塔--1panel)
- [四、方案进阶：组件独立拆分部署（微服务模式）](#四方案进阶组件独立拆分部署微服务模式)
- [五、日常运维与健康诊断](#五日常运维与健康诊断)
- [六、数据持久化与备份恢复](#六数据持久化与备份恢复)
- [七、MPAY Premium 动态码免输入扩展加载说明](#七mpay-premium-动态码免输入扩展加载说明)
- [八、常见问题排查 (FAQ)](#八常见问题排查-faq)
- [九、自动 CI/CD 与预构建镜像使用](#九自动-cicd-与预构建镜像使用)

---

## 一、部署方案选型

根据你当前现有的服务器环境，选择最适合的部署拓扑：

| 场景 | 推荐方案 | 说明 |
| :--- | :--- | :--- |
| **全新单机部署** | **一体化（All-in-One）+ MySQL 容器** | `docker-compose.yml` 默认模式，自带 Nginx、PHP-FPM、Worker 及 MySQL，一键拉起 |
| **已有外部 MySQL** | **一体化应用容器 + 外部数据库** | 关闭 Compose 中的 MySQL，配置 `DB_HOST` 直连宿主机或云 RDS |
| **已有外部 Nginx** | **`CONTAINER_MODE=fpm-worker` 组合** | 容器内仅运行 PHP-FPM 与后台守护任务，外部 Nginx 负责处理 SSL 与路由转发 |
| **组件完全解耦** | **微服务拆分模式** | 使用 `docker-compose.split.yml`，Nginx、FPM、Workers 分别作为独立容器运行 |

---

## 二、快速上手（3分钟起步）

### 1. 准备配置文件

进入项目根目录，从模板复制环境配置文件：

```bash
cp docker-compose.env.example docker-compose.env
```

按需修改 `docker-compose.env` 中的密码和端口（如默认 `APP_PORT=8080`，`DB_PASS=你的密码`）。

> 💡 **命令规范**：所有 `docker compose` 操作均统一显式指定 `--env-file docker-compose.env` 参数，确保环境变量准确加载。

---

### 方式 1：Web 网页向导安装（推荐）

1. **启动容器**：
   ```bash
   docker compose --env-file docker-compose.env up -d
   ```

2. **在浏览器打开安装向导**：
   访问 `http://你的服务器IP:8080/install`（如本机 `http://localhost:8080/install`）。

3. **完成四步向导**：
   - **① 环境检查**：系统已内置配置好的 PHP 8.4 及全套必须扩展，直接点击“下一步”。
   - **② 连接数据库**：
     - 若使用自带的 MySQL 容器：
       - 数据库地址填：`mysql`
       - 端口填：`3306`
       - 数据库名填：`mpay_v1`
       - 用户名填：`mpay_v1`
       - 密码填：`docker-compose.env` 中配置的 `DB_PASS`（默认 `mpay_secret_password`）
   - **③ 初始化系统**：设置管理员用户名与至少 12 位的密码，点击“立即安装”。
   - **④ 完成安装**：点击进入管理后台登录。

> 💡 **核心优势**：在未执行网页安装前，容器内的后台守护工作者（`payment:watch` 与 `payment:work`）会自动进入优雅等待循环，不会反复报错崩溃；安装完成后系统自动无缝启动任务。

---

### 方式 2：全自动静默一键安装

适合 DevOps 持续集成、脚本自动化部署或无需手动点击安装页面的场景。

1. 编辑 `docker-compose.env`，开启自动安装并设置初始管理员账号密码：
   ```ini
   AUTO_INSTALL=true
   ADMIN_USER=admin
   ADMIN_PASS=Admin1234567890
   INIT_APP_NAME=我的收款应用
   ```

2. 启动容器：
   ```bash
   docker compose --env-file docker-compose.env up -d
   ```

3. 容器首次启动时会自动等待 MySQL 就绪、执行数据表迁移并初始化管理员账号。完成后即可直接登录后台 `http://你的IP:8080/admin`。

---

## 三、已有外部基础设施接入

### 1. 接入外部已有 MySQL（云 RDS 或宿主机数据库）

如果你的服务器上已经安装了 MySQL（如通过宝塔、1Panel 安装，或云服务商 RDS），无须额外运行 Compose 中的 MySQL 容器：

1. **授权 MySQL 允许容器连接**：
   ```sql
   CREATE DATABASE IF NOT EXISTS mpay_v1 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
   CREATE USER 'mpay_v1'@'%' IDENTIFIED BY '你的复杂密码';
   GRANT ALL PRIVILEGES ON mpay_v1.* TO 'mpay_v1'@'%';
   FLUSH PRIVILEGES;
   ```

2. **配置 `docker-compose.env`**：
   - 如果外部 MySQL 就在**宿主机**上：
     ```ini
     # 利用 host.docker.internal 访问宿主机服务
     DB_HOST=host.docker.internal
     DB_PORT=3306
     DB_NAME=mpay_v1
     DB_USER=mpay_v1
     DB_PASS=你的复杂密码
     ```
     *(若宿主机 MySQL 监听在 `3006` 等非标端口，将 `DB_PORT=3006` 即可)*
   - 如果外部 MySQL 是**独立云数据库/局域网主机**：
     ```ini
     DB_HOST=192.168.1.100  # 或 RDS 域名
     DB_PORT=3306
     ```

3. **仅启动应用容器**：
   ```bash
   # 仅启动 app 服务，不启动自带的 mysql 容器
   docker compose --env-file docker-compose.env up -d app
   ```

---

### 2. 接入外部已有 Nginx / 宝塔 / 1Panel

若宿主机已有 Nginx（如已配置好 Let's Encrypt SSL 证书），推荐以下两种接入方式：

#### 方案 2.1：HTTP 反向代理模式（最简且推荐）
将已有 Nginx 作为网关代理到 MPAY 容器的 Web 端口（如 `127.0.0.1:8080`）：

在宿主机 Nginx 配置文件中加入：
```nginx
server {
    listen 80;
    server_name pay.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name pay.yourdomain.com;

    ssl_certificate /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    client_max_body_size 10m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;

        # 传递真实客户端 IP（非常关键，用于易支付客户端校验及限流）
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```
*参考模版：`docker/nginx/external-proxy.example.conf`*

#### 方案 2.2：FastCGI 直连模式（`fpm-worker` 组合）
如果你希望容器内**完全不运行任何 Nginx**，只把容器当作 PHP + 任务队列引擎，外部 Nginx 直接解析静态资源并 FastCGI 直连：

1. 编辑 `docker-compose.env`，切换运行模式：
   ```ini
   CONTAINER_MODE=fpm-worker
   ```
2. 在 `docker-compose.yml` 中放开 `9000:9000` 端口映射。
3. 外部 Nginx 虚拟主机配置直接使用 `docker/nginx/external-fastcgi.example.conf` 模板配置。

---

## 四、方案进阶：组件独立拆分部署（微服务模式）

对于需要容器职责严格单一（Microservices）的生产场景，可使用专用的拆分编排：

```bash
# 启动所有服务
docker compose --env-file docker-compose.env -f docker-compose.split.yml up -d

# 启动部分服务
docker compose --env-file docker-compose.env -f docker-compose.split.yml up -d web fpm worker
```

拆分编排包含四个服务：
- `web`：纯粹的 Nginx 镜像，专门负责静态文件、SSL、伪静态重写及安全过滤。
- `fpm`：仅运行 PHP-FPM（监听 9000 端口）。
- `worker`：仅负责运行 `payment:watch` 与 `payment:work` 后台守护进程。
- `mysql`：独立数据库服务（可按需替换为外部数据库）。

四个容器通过共享 Docker 数据卷 `mpay_storage` 与 `mpay_runtime` 保障文件锁与上传文件协同。

---

## 五、日常运维与健康诊断

### 1. 运行系统自带的健康检查工具 (`system:doctor`)

MPAY 内置了生产诊断工具，可以在容器内一键检查 PHP 扩展、目录隔离、MySQL 严格模式及后台工作者心跳：

```bash
docker compose --env-file docker-compose.env exec app php think system:doctor
```

输出示例：
```text
[ok] php：PHP 8.4.x；libcurl 7.88.1；UTF-8 工具可用；缺失扩展：无；图片 PNG/JPEG/WebP 编解码可用
[ok] site：站点运行模式正常
[ok] runtime：运行目录可写且与 public 隔离
[ok] database：MySQL 8.0.x；会话时区/字符集/严格模式符合要求
[ok] migrations：建表进度与表存在性检查通过
[ok] worker_heartbeats：watch 与 work 工作者近期均有心跳上报
```

### 2. 查看容器运行日志与任务心跳

- **查看所有实时日志**：
  ```bash
  docker compose --env-file docker-compose.env logs -f app
  ```
- **查看后台任务状态**：
  进入容器查看守护进程：
  ```bash
  docker compose --env-file docker-compose.env exec app supervisorctl status
  ```
  输出示例：
  ```text
  nginx             RUNNING   pid 15, uptime 0:10:00
  payment-watch     RUNNING   pid 18, uptime 0:10:00
  payment-work      RUNNING   pid 19, uptime 0:10:00
  php-fpm           RUNNING   pid 14, uptime 0:10:00
  ```

### 3. 进入容器命令行

```bash
docker compose --env-file docker-compose.env exec app bash
```

---

## 六、数据持久化与备份恢复

### 持久化数据卷说明
Docker 部署在默认配置下使用以下三个命名数据卷保存重要数据，升级与重启容器数据不丢失：
- `mpay_db`：MySQL 数据库物理数据文件。
- `mpay_storage`：后台及收银台上传的收款码、商品图片等附件（对应容器内 `/var/www/html/public/storage`）。
- `mpay_runtime`：安装锁（`install.lock`）、登录 Session、短期缓存、心跳锁文件等。

### 备份命令
1. **导出数据库 SQL**：
   ```bash
   docker compose --env-file docker-compose.env exec mysql mysqldump -uroot -p$MYSQL_ROOT_PASSWORD mpay_v1 > mpay_backup_$(date +%Y%m%d).sql
   ```
2. **备份上传的图片附件**：
   ```bash
   docker run --rm -v mpay_storage:/data -v $(pwd):/backup alpine tar czf /backup/storage_backup_$(date +%Y%m%d).tar.gz -C /data .
   ```

### 恢复命令
1. **导入数据库 SQL**：
   ```bash
   docker compose --env-file docker-compose.env exec -T mysql mysql -uroot -p$MYSQL_ROOT_PASSWORD mpay_v1 < mpay_backup.sql
   ```
2. **恢复图片附件**：
   ```bash
   docker run --rm -v mpay_storage:/data -v $(pwd):/backup alpine tar xzf /backup/storage_backup.tar.gz -C /data
   ```

---

## 七、MPAY Premium 动态码免输入扩展加载说明

如需使用天阙、拉卡拉、星驿付、付呗或易宝的免输入动态码收款，官方提供了原生 SDK 扩展包（`mpay_premium.so` 与 `libphpx.so`）。

由于本 Docker 镜像基于 **Debian 12 Bookworm (Linux x86_64, glibc, PHP 8.4 NTS)** 构建，完美满足该扩展的二进制运行条件。

挂载与启用方法：
1. 将扩展目录放到宿主机，如 `/opt/mpay_premium/`。
2. 在 `docker-compose.yml` 的 `app` 服务 `volumes` 中挂载该目录：
   ```yaml
   volumes:
     - mpay_storage:/var/www/html/public/storage
     - mpay_runtime:/var/www/html/runtime
     - /opt/mpay_premium:/opt/mpay_premium:ro
   ```
3. 在宿主机新建 `docker/php/99-premium.ini` 并挂载到 `/usr/local/etc/php/conf.d/99-premium.ini`：
   ```ini
   extension=/opt/mpay_premium/1.2.2/php84_linux_x86_64_nts/mpay_premium.so
   ```
4. 执行 `docker compose --env-file docker-compose.env restart app` 即可生效。

---

## 八、常见问题排查 (FAQ)

### Q1: 打开网页提示“数据库连接超时”或“Connection refused”？
- 若使用自带 MySQL 容器：请确认 `DB_HOST=mysql`，且 MySQL 容器已经完成初始化并处于 healthy 状态。可通过 `docker compose --env-file docker-compose.env ps` 查看状态。
- 若连接宿主机已有 MySQL：确保在 `docker-compose.env` 中配置 `DB_HOST=host.docker.internal`，且宿主机 MySQL 监听了 `0.0.0.0` 而非 `127.0.0.1`，同时数据库账号允许来自 `%` 或 `172.%.%.%` 的连接。

### Q2: 易支付接口或后台通道测试单提示“客户端 IP 为 127.0.0.1”？
- 请检查最外层的反向代理（如 Nginx）是否正确传递了 `X-Forwarded-For` 头。
- 容器内 Nginx 配置中已经开启了 `real_ip_header X-Forwarded-For`，若反向代理位于特殊网段，可在 `docker/nginx/nginx.conf` 中追加对应的 `set_real_ip_from <代理IP网段>;`。

### Q3: 重新拉取新代码后如何更新？
1. 拉取最新代码：`git pull`
2. 重新构建并平滑重启容器：
   ```bash
   docker compose --env-file docker-compose.env build --no-cache
   docker compose --env-file docker-compose.env up -d
   ```
3. 检查系统状态：
   ```bash
   docker compose --env-file docker-compose.env exec app php think system:doctor
   ```

---

## 九、自动 CI/CD 与预构建镜像使用

本项目已配置基于 **GitHub Actions** 的全自动镜像构建与发布流水线（位于 `.github/workflows/docker-publish.yml`）。

### 1. 流水线自动化特性
- **自动触发条件**：
  - 代码推送到主分支（`v1` 或 `main`）时，自动构建并发布 `latest` 及分支标签；
  - 发布版本 Tag（如 `v1.0.0`）时，自动打上语义化版本标签发布；
  - 支持在 GitHub 仓库 Actions 页面手动点击 `workflow_dispatch` 一键触发。
- **跨平台多架构支持**：原生支持 `linux/amd64` 与 `linux/arm64`（Apple Silicon、AWS Graviton 等），各架构服务器直接拉取运行。
- **免费原生发布目标**：默认推送到 GitHub Packages 原生容器仓库：
  ```text
  ghcr.io/nothing248/mpay:latest
  ```

### 2. 免本地编译直接使用预构建镜像
`docker-compose.yml` 与 `docker-compose.split.yml` 中的镜像参数均已支持环境变量动态适配（`image: ${IMAGE_NAME:-mpay:latest}`）。

在生产服务器上，如果无需本地编译，直接在 `docker-compose.env` 中指定镜像仓库地址即可，**无需改动任何 yml 文件**：

```ini
# 在 docker-compose.env 中配置远程镜像地址
IMAGE_NAME=ghcr.io/nothing248/mpay:latest
```

使用命令一键秒级拉取与运行：
```bash
docker compose --env-file docker-compose.env pull
docker compose --env-file docker-compose.env up -d
```

### 3. 可选：推送到 Docker Hub
若需要同时推送到公共 Docker Hub，只需在 GitHub 仓库设置（Settings → Secrets and variables → Actions）中添加以下两个密钥：
- `DOCKERHUB_USERNAME`：Docker Hub 用户名
- `DOCKERHUB_TOKEN`：Docker Hub 访问令牌（Access Token）

Actions 将在每次发布时自动同步推送到 Docker Hub。

