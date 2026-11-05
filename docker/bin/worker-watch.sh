#!/bin/sh
set -e

# ==============================================================================
# MPAY payment:watch 智能守护包装脚本
# 在系统未完成安装前保持等待循环，避免 Supervisor 频繁崩溃标记 FATAL；
# 一旦检测到安装锁 install.lock，立即无缝无损启动流水与动态码监听任务。
# ==============================================================================

LOCK_FILE="/var/www/html/runtime/install.lock"

while [ ! -f "$LOCK_FILE" ]; do
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [worker-watch] 等待 MPAY 系统安装完成 ($LOCK_FILE 未就绪)..."
    sleep 5
done

echo "[$(date '+%Y-%m-%d %H:%M:%S')] [worker-watch] 安装就绪，启动 payment:watch 守护任务..."
exec php /var/www/html/think payment:watch
