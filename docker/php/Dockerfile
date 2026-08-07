# ---------- Stage 1: сборка фронтенда (Vue + Inertia) ----------
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources/ ./resources/
COPY vite.config.js tailwind.config.js ./
RUN npm run build

# ---------- Stage 2: зависимости composer ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY database/ database/
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --ignore-platform-reqs \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --prefer-dist

# ---------- Stage 3: финальный образ (PHP-FPM) ----------
FROM php:8.3-fpm-alpine AS runtime
WORKDIR /var/www/html

# dl-cdn.alpinelinux.org из туркменской сети не открывается без VPN, поэтому при
# недоступности штатного зеркала репозитории переписываются на запасное.
ARG ALPINE_MIRROR=https://mirror.yandex.ru/mirrors/alpine

# Рантайм-библиотеки остаются, build-зависимости удаляются. pecl докачки не умеет:
# оборванный tgz оседает в кэше и валит сборку, поэтому перед каждой попыткой
# установки redis каталог загрузок сносится целиком.
RUN { apk update >/dev/null 2>&1 \
      || sed -i "s|https://dl-cdn.alpinelinux.org/alpine|${ALPINE_MIRROR}|g" /etc/apk/repositories; } \
    && apk add --no-cache \
        libpq icu-libs libzip libpng libjpeg-turbo libwebp freetype \
        postgresql16-client zip unzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
        libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql pgsql zip gd bcmath intl mbstring opcache pcntl \
    && { for i in 1 2 3; do \
            rm -rf /tmp/pear; \
            if pecl install redis; then redis_installed=1; break; fi; \
            sleep 5; \
         done; [ -n "${redis_installed:-}" ]; } \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/php.ini     /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/www.conf    /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

# порядок важен: код первым, собранное — поверх
COPY . .
COPY --from=vendor   /app/vendor       ./vendor
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs \
             storage/app/public storage/app/backups \
             bootstrap/cache \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && ln -sfn ../storage/app/public public/storage

ARG UID=1000
RUN adduser -u ${UID} -D -H -G www-data appuser \
    && chown -R appuser:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x /usr/local/bin/entrypoint.sh

USER appuser

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]


# ---------- Stage 4: nginx с готовым public/ ----------
FROM nginx:1.31.3-alpine AS web
COPY --from=runtime /var/www/html/public /var/www/html/public
# Штатный default.conf тоже слушает 80 с server_name localhost и перехватывает
# роль сервера по умолчанию, поэтому уезжает до копирования своего конфига.
RUN rm -f /etc/nginx/conf.d/default.conf
COPY docker/nginx/conf.d/ /etc/nginx/conf.d/
