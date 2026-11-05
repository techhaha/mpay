#!/bin/sh
set -e

# ==============================================================================
# MPAY Docker 容器入口分发脚本
# 1. 运行时目录检查与属主校准 (www-data)
# 2. 环境变量预填充与 .env 初始化
# 3. 可选自动化建表与管理员初始化 (AUTO_INSTALL=true)
# 4. 根据 CONTAINER_MODE 启动相应服务角色
# ==============================================================================

APP_DIR="/var/www/html"
RUNTIME_DIR="${APP_DIR}/runtime"
STORAGE_DIR="${APP_DIR}/public/storage"

# 1. 确保必要持久化目录存在并修正属主
mkdir -p "${RUNTIME_DIR}/workers" \
         "${RUNTIME_DIR}/access" \
         "${RUNTIME_DIR}/session" \
         "${RUNTIME_DIR}/log" \
         "${STORAGE_DIR}"

chown -R www-data:www-data "${RUNTIME_DIR}" "${STORAGE_DIR}"
chmod 775 "${RUNTIME_DIR}"
chmod 755 "${STORAGE_DIR}"

# 2. 若未挂载 .env 且指定了环境变量，按 .example.env 模板生成基础 .env
if [ ! -f "${APP_DIR}/.env" ]; then
    echo "[entrypoint] 正在根据环境变量生成初始 .env 配置文件..."
    cat <<EOF > "${APP_DIR}/.env"
; MPAY Docker 自动生成的环境配置
APP_DEBUG = ${APP_DEBUG:-false}
MPAY_ENV = ${MPAY_ENV:-production}
MPAY_HTTP_DEV_HOSTS = ${MPAY_HTTP_DEV_HOSTS:-}
MPAY_HTTP_DEV_PORTS = ${MPAY_HTTP_DEV_PORTS:-}
DB_HOST = ${DB_HOST:-127.0.0.1}
DB_PORT = ${DB_PORT:-3306}
DB_NAME = ${DB_NAME:-mpay_v1}
DB_USER = ${DB_USER:-mpay_v1}
DB_PASS = ${DB_PASS:-}
DEFAULT_LANG = ${DEFAULT_LANG:-zh-cn}
EOF
    chown www-data:www-data "${APP_DIR}/.env"
    chmod 664 "${APP_DIR}/.env"
fi

# 3. 可选全自动初始化流程 (AUTO_INSTALL=true 且尚未安装)
if [ "${AUTO_INSTALL}" = "true" ] || [ "${AUTO_INSTALL}" = "1" ]; then
    if [ ! -f "${RUNTIME_DIR}/install.lock" ]; then
        echo "[entrypoint] 检测到 AUTO_INSTALL=true，正在等待数据库就绪..."
        DB_TARGET_HOST="${DB_HOST:-127.0.0.1}"
        DB_TARGET_PORT="${DB_PORT:-3306}"
        DB_TARGET_NAME="${DB_NAME:-mpay_v1}"
        DB_TARGET_USER="${DB_USER:-mpay_v1}"
        DB_TARGET_PASS="${DB_PASS:-}"

        php -r '
        $host = getenv("DB_HOST") ?: "127.0.0.1";
        $port = getenv("DB_PORT") ?: 3306;
        $name = getenv("DB_NAME") ?: "mpay_v1";
        $user = getenv("DB_USER") ?: "mpay_v1";
        $pass = getenv("DB_PASS") ?: "";
        $maxAttempts = 30;
        for ($i = 1; $i <= $maxAttempts; $i++) {
            try {
                $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                    PDO::ATTR_TIMEOUT => 2,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                echo "[entrypoint] 数据库连接成功！\n";
                exit(0);
            } catch (\Throwable $e) {
                echo "[entrypoint] 等待数据库就绪 ($i/{$maxAttempts}): " . $e->getMessage() . "\n";
                sleep(2);
            }
        }
        exit(1);
        '

        echo "[entrypoint] 执行数据迁移 (system:migrate)..."
        su -s /bin/sh www-data -c "php ${APP_DIR}/think system:migrate"

        if [ -n "${ADMIN_USER}" ] && [ -n "${ADMIN_PASS}" ]; then
            echo "[entrypoint] 执行系统初始化 (system:initialize)..."
            INIT_JSON="/tmp/mpay_init_$$.json"
            cat <<EOF > "${INIT_JSON}"
{
    "username": "${ADMIN_USER}",
    "password": "${ADMIN_PASS}",
    "password_confirmation": "${ADMIN_PASS}",
    "app_name": "${INIT_APP_NAME:-默认应用}"
}
EOF
            chmod 600 "${INIT_JSON}"
            chown www-data:www-data "${INIT_JSON}"
            su -s /bin/sh www-data -c "php ${APP_DIR}/think system:initialize --input=${INIT_JSON}"
            rm -f "${INIT_JSON}"
            echo "[entrypoint] 系统全自动初始化完成！"
        else
            echo "[entrypoint] 未设置 ADMIN_USER 或 ADMIN_PASS，请在浏览器访问 /install 完成最后初始化"
        fi
    fi
fi

# 4. 指令与模式调度分发
# 如果外部传入了自定义命令（如 php think ..., bash, sh），且不是默认的 supervisord 命令，直接执行
if [ "$#" -gt 0 ] && [ "$1" != "/usr/bin/supervisord" ]; then
    exec "$@"
fi

# 根据环境变量 CONTAINER_MODE 选择运行模式
MODE="${CONTAINER_MODE:-all-in-one}"
echo "[entrypoint] 启动 MPAY 容器，当前运行模式: ${MODE}"

case "${MODE}" in
    "all-in-one")
        # 一体化模式：Nginx + PHP-FPM + Workers
        exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
        ;;
    "fpm-worker")
        # FPM + Worker 组合模式（外部已有 Nginx）
        exec /usr/bin/supervisord -c /etc/supervisor/supervisord-fpm-worker.conf
        ;;
    "fpm")
        # 仅启动 PHP-FPM
        exec /usr/local/sbin/php-fpm -F
        ;;
    "worker")
        # 仅启动后台 Workers
        exec /usr/bin/supervisord -c /etc/supervisor/supervisord-worker.conf
        ;;
    "worker-watch")
        # 仅启动 watch 任务
        exec /usr/local/bin/worker-watch.sh
        ;;
    "worker-work")
        # 仅启动 work 任务
        exec /usr/local/bin/worker-work.sh
        ;;
    *)
        echo "[entrypoint] 未知模式: ${MODE}，默认降级启动 all-in-one"
        exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
        ;;
esac
