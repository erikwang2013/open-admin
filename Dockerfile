# ============================================================
# 开放管理后台 — 生产 Dockerfile
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
# ============================================================

FROM php:8.3-cli-alpine

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# 镜像加速 + 运行时依赖（curl/unzip 保留，其余为扩展运行库）
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories \
    && apk update --no-cache \
    && apk add --no-cache \
        libpng \
        libjpeg-turbo \
        freetype \
        oniguruma \
        libxml2 \
        libzip \
        curl \
        unzip

# PHP 扩展 + 原生 Redis（support\Redis 硬依赖）全部编译完成后，
# 一次性删除 dev 头文件与 $PHPIZE_DEPS 虚拟包，镜像只保留运行库
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-source extract \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo pdo_mysql \
        pcntl \
        mbstring \
        gd \
        xml \
        dom \
        xmlwriter \
        zip \
    && pecl install redis \
    && docker-php-ext-enable opcache pcntl redis \
    && docker-php-source delete \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/* /tmp/*

# OPcache 生产配置
RUN echo "opcache.enable=1" >> "$PHP_INI_DIR/php.ini" \
    && echo "opcache.enable_cli=1" >> "$PHP_INI_DIR/php.ini" \
    && echo "opcache.memory_consumption=128" >> "$PHP_INI_DIR/php.ini" \
    && echo "opcache.max_accelerated_files=10000" >> "$PHP_INI_DIR/php.ini"

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN mkdir -p /app
WORKDIR /app

# 依赖安装（利用 Docker 层缓存，锁定版本可复现）
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader \
    && rm -rf /root/.composer/cache /tmp/*

COPY . .

# 运行时 / 上传目录预建，生产 compose 以宿主机目录覆盖挂载
RUN mkdir -p /app/runtime /app/public/upload

EXPOSE 8791
CMD ["php", "start.php", "start"]
