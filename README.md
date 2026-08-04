# ARÔME Admin

Админ-панель для управления каталогом парфюмерии: товары, цены и скидки, остатки по точкам,
сотрудники и права, импорт прайса из Excel, синхронизация устройств продавцов и журнал действий.

Приложение построено как SPA на Inertia.js: серверная маршрутизация Laravel + Vue 3 на клиенте,
без vue-router и без сторонних UI-китов — все компоненты собственные (см. [Дизайн-система](#дизайн-система)).

> **Статус:** каркас проекта. Настроены Laravel 13, Inertia v3, Vue 3, Vite, окружение Docker.
> Разделы панели реализуются по спецификации [AROMA-ADMIN-PROMPT.md](AROMA-ADMIN-PROMPT.md) —
> это основной документ с точными требованиями к вёрстке, данным и поведению экранов.

---

## Стек

| Слой | Технологии |
|---|---|
| Backend | PHP 8.3, Laravel 13, Inertia Laravel v3 |
| Frontend | Vue 3, `@inertiajs/vue3` v3, Vite 8, Tailwind CSS v4 (утилиты; основа — CSS-переменные) |
| БД | PostgreSQL 16 (Docker) / SQLite (локально по умолчанию) |
| Кэш, очереди | Redis (Docker), драйверы `database` по умолчанию |
| Инструменты | Laravel Boost, Pail, Pint, PHPUnit 12 |

---

## Требования

- PHP 8.3+, Composer 2
- Node.js 20+, npm
- Docker и Docker Compose — если поднимаете окружение в контейнерах

---

## Быстрый старт

### Вариант 1 — Docker (PostgreSQL + Redis + Nginx)

```bash
cp .env.example .env
# в .env укажите параметры БД, совпадающие с docker-compose.yml:
# DB_CONNECTION=pgsql, DB_HOST=db, DB_PORT=5432,
# DB_DATABASE=aroma_db, DB_USERNAME=admin, DB_PASSWORD=secret
# COMPOSE_PROJECT_NAME=aroma

docker compose up -d --build
docker compose exec app php artisan key:generate
```

Панель — http://localhost:8000

Контейнер `app` на каждом старте сам прогоняет `migrate --force` и `db:seed --force`,
поэтому база готова к первому входу без ручных команд. Сидер создаёт единственную
учётку — главного администратора по `ADMIN_LOGIN` / `ADMIN_PASSWORD` из `.env`
(по умолчанию `admin` / `admin12345`), и при перезапуске освежает её пароль из `.env`.
Доступ к служебной консоли `/su` — `docker compose exec app php artisan aroma:superadmin`.

Сервисы: `app` (PHP-FPM 8.3), `nginx` (порт 8000), `db` (PostgreSQL 16, порт 5432),
`redis` (порт 6379). Фронтенд собирается на этапе сборки образа (`npm run build`).

### Вариант 2 — локально

```bash
composer setup     # install + .env + key:generate + migrate + npm install + npm run build
composer run dev   # сервер, обработчик очереди, логи (pail) и Vite одной командой
```

`composer setup` использует настройки БД из `.env`; по умолчанию это SQLite
(`database/database.sqlite`). Приложение будет доступно на http://localhost:8000.

---

## Полезные команды

```bash
composer run dev                 # server + queue + pail + vite (concurrently)
npm run dev                      # только Vite с HMR
npm run build                    # production-сборка фронтенда

php artisan migrate:fresh --seed # пересобрать пустую БД и создать администратора
php artisan aroma:superadmin     # выдать доступ к служебной консоли /su
php artisan route:list           # список маршрутов
php artisan pail                 # живой просмотр логов

composer test                    # config:clear + весь набор тестов
php artisan test --compact       # то же, компактный вывод
php artisan test --filter=ProductTest

vendor/bin/pint                  # форматирование PHP по стилю проекта
```

Если изменения фронтенда не видны в браузере — не запущен `npm run dev`
или не выполнен `npm run build`.

---

## Структура

```
app/
  Http/Controllers/     тонкие контроллеры: Form Request → сервис → Inertia::render
  Http/Middleware/      HandleInertiaRequests — общие props (в т.ч. состояние модулей)
  Http/Requests/        валидация форм
  Services/             бизнес-логика: цены и скидки, разбор Excel, пароли, EAN-13
  Repositories/         запросы к БД
  Models/
database/
  migrations/  factories/  seeders/     AdminSeeder — учётка администратора, и только
resources/
  js/app.js             createInertiaApp + resolvePageComponent
  js/Layouts/           AdminLayout, AuthLayout, SuLayout
  js/Pages/             экраны, резолвятся по имени из Inertia::render
  js/Components/        общие компоненты (AppButton, DataTable, Modal, SideDrawer, …)
  css/app.css           токены дизайн-системы
  views/                единственный blade-шаблон приложения
routes/web.php
docker/                 php/Dockerfile (multi-stage), nginx/conf.d/aroma.conf
tests/                  Feature и Unit (PHPUnit)
```

---

## Разделы панели

| Раздел | Маршрут | Модуль |
|---|---|---|
| Вход | `/login` | — |
| Товары | `/products` | — |
| Импорт из Excel | `/import` | `import` |
| Пользователи | `/users` | только главный администратор |
| Матрица прав | `/rights` | — |
| Точки и склады | `/points` | `points` |
| Синхронизация устройств | `/devices` | `devices` |
| Журнал действий | `/audit` | `audit` |
| Служебная консоль | `/su` | только суперадмин |

### Модули (feature flags)

Таблица `modules` включает и выключает функциональность целиком: раздел, связанные
фильтры, колонки таблиц и поля форм. Данные при выключении остаются в БД.

- `points` — торговые точки (выкл. по умолчанию)
- `warehouses` — склады, зависит от `points` (выкл. по умолчанию)
- `productPoints` — остатки товара по точкам, зависит от `points` (выкл. по умолчанию)
- `import`, `devices`, `audit` — включены по умолчанию

Модуль считается включённым, только если включён он сам и рекурсивно все его зависимости.
Эффективное состояние отдаётся во все страницы через `HandleInertiaRequests::share()`
как `modules: { points: bool, … }`. Скрытый раздел недоступен и по прямому URL — контроллер
возвращает 404. Управление флагами — только из служебной консоли `/su`.

---

## Дизайн-система

Оформление задано CSS-переменными в `resources/css/app.css`. Ключевые правила:

- Шрифты: Playfair Display (заголовки), IBM Plex Sans (интерфейс), IBM Plex Mono (все числа
  и микро-заголовки, с `font-variant-numeric: tabular-nums`).
- `border-radius` — только `2px` у инпутов, селектов и кнопок; всё остальное с прямыми углами.
- Тени — только у модалок и выезжающей панели.
- Разделители — волосяные линии: `--rule-soft` внутри таблиц, `--rule-strong` между зонами.
- Статус — подчёркнутый текст, а не «пилюля».
- Деньги — формат `1 415,88`, валюта `TMT`.
- `body` не скроллится: приложение `height: 100vh; overflow: hidden`, скроллятся только
  тела таблиц.

Полная палитра, ограничения и чек-лист приёмки — в
[AROMA-ADMIN-PROMPT.md](AROMA-ADMIN-PROMPT.md) (§2 и §15).

---

## Тесты

```bash
php artisan test --compact
```

Покрытие по спецификации: фильтрация и сортировка товаров, расчёт цены со скидкой,
правила валидации товара, контрольная цифра EAN-13, каскадное выключение модулей,
матрица прав (роль `admin` неизменяема, скрытые поля не попадают в API) и генератор паролей.

---

## Соглашения

Правила для разработчиков и агентов собраны в [CLAUDE.md](CLAUDE.md): структура кода,
использование Artisan-команд, форматирование через Pint, тесты на PHPUnit.
Перед финализацией изменений в PHP выполняйте `vendor/bin/pint --dirty`.
