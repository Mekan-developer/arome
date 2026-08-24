FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js tailwind.config.js ./
COPY resources ./resources
RUN npm run build          # → /app/public/build


FROM php:8.3-fpm AS app

WORKDIR /var/www/laravel

# deb.debian.org is unreachable from the office network, so apt goes through a
# mirror. Override at build time when a direct connection is available:
#   docker compose build --build-arg DEBIAN_MIRROR=https://deb.debian.org php
ARG DEBIAN_MIRROR=https://mirror.yandex.ru
RUN sed -i "s|https\?://deb.debian.org|${DEBIAN_MIRROR}|g" /etc/apt/sources.list.d/debian.sources

# GD (with JPEG/WebP/FreeType) powers PhotoConverter — without it every upload
# silently falls back to storing the original file instead of a WebP.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libpq-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pcntl pdo pdo_pgsql pgsql zip exif \
    && rm -rf /var/lib/apt/lists/*

# Bind-mount с хоста принадлежит чужому UID, а процессы идут от www-data —
# без этого git (и вызывающий его composer) ругается на "dubious ownership".
RUN git config --system --add safe.directory /var/www/laravel

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer

# Сначала только манифесты — composer install закэшируется,
# пока composer.json/lock не меняются, даже если код меняется постоянно
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

# Теперь весь код
COPY . .

RUN composer dump-autoload --optimize \
    && composer run-script post-autoload-dump || true


COPY --from=assets /app/public/build ./public/build

# Каталоги storage/* и bootstrap/cache вычищены .dockerignore и приезжают пустыми
# от root, а php-fpm со scheduler'ом работают под www-data. Права ставим здесь:
# прод-том storage_data наследует владельца из образа в момент создания.
RUN mkdir -p \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/logs \
      storage/app/public \
      storage/app/private \
      bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache



FROM nginx:1.27-alpine AS web
COPY docker/nginx/conf.d/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/laravel/public /var/www/laravel/public
