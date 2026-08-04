#!/bin/sh
set -e

# Контейнер поднимается сам: схема догоняется до актуальной, а сидер выдаёт
# учётку администратора из ADMIN_LOGIN / ADMIN_PASSWORD. Оба шага идемпотентны —
# повторный старт ничего не дублирует, только освежает пароль администратора.
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

exec docker-php-entrypoint "$@"
