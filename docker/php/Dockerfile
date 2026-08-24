FROM php:8.3-fpm

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
