#!/bin/sh
set -e

# ==============================================================================
# MPAY payment:work 智能守护包装脚本
# 负责商户通知、重试、订单超时关闭及日常清理；
# 未安装时等待，安装后平滑 exec 启动。
# ==============================================================================

LOCK_FILE="/var/www/html/runtime/install.lock"

while [ ! -f "$LOCK_FILE" ]; do
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [worker-work] 等待 MPAY 系统安装完成 ($LOCK_FILE 未就绪)..."
    sleep 5
done

echo "[$(date '+%Y-%m-%d %H:%M:%S')] [worker-work] 安装就绪，启动 payment:work 守护任务..."
exec php /var/www/html/think payment:work
