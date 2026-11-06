ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-bookworm

LABEL maintainer="MPAY Team"
LABEL description="MPAY Payment Management System Production Docker Image"

# 安装系统基础工具、Nginx、Supervisor 及所需扩展依赖库
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    curl \
    unzip \
    git \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    libgmp-dev \
    libonig-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        bcmath \
        mbstring \
        fileinfo \
        pcntl \
        gmp \
        opcache \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# 从官方镜像引入 Composer 2
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 配置 PHP、PHP-FPM、Nginx、Supervisor 及 Worker 运行脚本
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-mpay.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/supervisor/supervisord-fpm-worker.conf /etc/supervisor/supervisord-fpm-worker.conf
COPY docker/supervisor/supervisord-worker.conf /etc/supervisor/supervisord-worker.conf
COPY docker/bin/worker-watch.sh /usr/local/bin/worker-watch.sh
COPY docker/bin/worker-work.sh /usr/local/bin/worker-work.sh
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /usr/local/bin/worker-watch.sh /usr/local/bin/worker-work.sh /entrypoint.sh

# 设置工作目录
WORKDIR /var/www/html

# 复制应用代码
COPY . /var/www/html

# 执行 Composer 依赖安装（生产模式，不含 dev 依赖，优化自动加载并触发服务发现）
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    && mkdir -p /var/www/html/runtime /var/www/html/public/storage \
    && chown -R www-data:www-data /var/www/html

# 暴露端口：80 为内置 Nginx Web 端口，9000 为 PHP-FPM FastCGI 端口
EXPOSE 80 9000

# 默认工作环境
ENV CONTAINER_MODE=all-in-one

ENTRYPOINT ["/entrypoint.sh"]
CMD []
