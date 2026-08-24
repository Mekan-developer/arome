#!/bin/sh
set -e

# Каталоги storage приезжают из образа в момент создания тома, но том переживает
# пересборку и о новых подпапках не узнает. Досоздать их дешевле, чем ловить
# «failed to open stream» на первом же запросе.
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    storage/app/private \
    storage/app/backups \
    bootstrap/cache

# Схему догоняет только php: планировщик работает с той же базой, и параллельный
# migrate ловил бы блокировку. Оба шага идемпотентны — повторный старт ничего не
# дублирует, только освежает пароль администратора из ADMIN_LOGIN/ADMIN_PASSWORD.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    attempt=1
    until php artisan migrate --force --no-interaction; do
        if [ "$attempt" -ge 10 ]; then
            echo "Миграции не прошли после 10 попыток — контейнер не стартует." >&2
            exit 1
        fi

        echo "База ещё не отвечает, попытка $attempt из 10 — повтор через 3 с."
        attempt=$((attempt + 1))
        sleep 3
    done

    php artisan db:seed --force --no-interaction
fi

# Кэши собираются на старте, а не в образе: образ один на все окружения, а
# config/route/view зависят от переменных конкретного контейнера. В dev код
# примонтирован с хоста, там кэш только мешает — OPTIMIZE_ON_BOOT=false.
if [ "${OPTIMIZE_ON_BOOT:-true}" = "true" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
else
    php artisan optimize:clear
fi

exec docker-php-entrypoint "$@"
