# ---------- Stage 1: сборка фронтенда (Vue + Inertia) ----------
FROM node:20-alpine AS frontend
workdir /app
copy package.json package-lock.json ./
run npm ci
copy . .
run npm run build


# ---------- Stage 2: PHP + зависимости composer ----------
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
FROM php:8.3-fpm-alpine
WORKDIR /var/www/html
RUN apk add --no-cache \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    zip \
    gd \
    bcmath \
    intl \
    mbstring \
    opcache

# Redis-расширение (pecl)
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY . .

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
