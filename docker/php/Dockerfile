# ---------- 1. фронтенд ----------
FROM node:20-alpine AS frontend
WORKDIR /app
ARG NPM_REGISTRY=https://registry.npmjs.org/
COPY package.json package-lock.json ./
RUN npm config set registry "${NPM_REGISTRY}" && npm ci
COPY resources/ ./resources/
COPY vite.config.js tailwind.config.js ./
RUN npm run build

# ---------- 2. composer ----------
FROM composer:2 AS vendor
WORKDIR /app
ARG COMPOSER_MIRROR=
COPY composer.json composer.lock ./
RUN if [ -n "${COMPOSER_MIRROR}" ]; then \
        composer config -g repos.packagist composer "${COMPOSER_MIRROR}"; \
    fi \
    && composer install --no-dev --ignore-platform-reqs --no-interaction \
                        --no-plugins --no-scripts --prefer-dist

# ---------- 3. runtime (PHP-FPM) ----------
FROM php:8.3-fpm-alpine AS runtime
WORKDIR /var/www/html
ARG ALPINE_MIRROR=https://mirror.yandex.ru/mirrors/alpine
ARG UID=1000

RUN set -eu; \
    for attempt in 1 2 3 4 5 6; do \
        { apk update >/dev/null 2>&1 \
            || sed -i "s|https://dl-cdn.alpinelinux.org/alpine|${ALPINE_MIRROR}|g" /etc/apk/repositories; } \
        && apk add --no-cache \
            icu-libs libzip libpng libjpeg-turbo libwebp freetype postgresql16-client \
        && apk add --no-cache --virtual .build-deps \
            $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
            libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
        && { apk_ok=1; break; }; \
        echo "apk сорвался (попытка ${attempt}) — повтор через 30 с" >&2; \
        apk del .build-deps >/dev/null 2>&1 || true; \
        sleep 30; \
    done; \
    [ -n "${apk_ok:-}" ]; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql zip gd bcmath intl opcache pcntl; \
    apk del .build-deps; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini       /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/opcache.ini   /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/www.conf      /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

# порядок важен: код первым, собранное — поверх
COPY . .
COPY --from=vendor   /app/vendor       ./vendor
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs \
             storage/app/public storage/app/backups bootstrap/cache \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && ln -sfn ../storage/app/public public/storage \
    && adduser -u ${UID} -D -H -G www-data appuser \
    && chown -R appuser:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x /usr/local/bin/entrypoint.sh

USER appuser
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

# ---------- 4. nginx ----------
FROM nginx:1.31.3-alpine AS web
COPY --from=runtime /var/www/html/public /var/www/html/public
RUN rm -f /etc/nginx/conf.d/default.conf
COPY docker/nginx/conf.d/ /etc/nginx/conf.d/
