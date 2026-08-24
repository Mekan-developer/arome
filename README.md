# ARÔME Admin

Админ-панель для управления каталогом парфюмерии: товары, цены и скидки, остатки по точкам,
сотрудники и права, импорт прайса из Excel, синхронизация устройств продавцов и журнал действий.

Приложение построено как SPA на Inertia.js: серверная маршрутизация Laravel + Vue 3 на клиенте,
без vue-router и без сторонних UI-китов — все компоненты собственные (см. [Дизайн-система](#дизайн-система)).

> **Статус:** разделы панели реализованы, окружение Docker готово к развёртыванию —
> см. [Деплой](#деплой).

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
# в .env укажите параметры БД под docker-compose.yml:
# DB_CONNECTION=pgsql, DB_HOST=db, DB_PORT=5432,
# DB_DATABASE=aroma, DB_USERNAME=aroma, DB_PASSWORD=…, REDIS_PASSWORD=…

docker compose up -d --build
docker compose run --rm artisan key:generate
```

Панель — http://localhost:8090

Контейнер `php` на каждом старте сам прогоняет `migrate --force` и `db:seed --force`
(`RUN_MIGRATIONS=true` в `docker-compose.yml`), поэтому база готова к первому входу
без ручных команд. Сидер создаёт единственную учётку — главного администратора по
`ADMIN_LOGIN` / `ADMIN_PASSWORD` из `.env` (по умолчанию `admin` / `admin12345`), и
при перезапуске освежает её пароль из `.env`. Служебная консоль `/su` —
`docker compose run --rm artisan aroma:superadmin`.

Сервисы: `php` (PHP-FPM 8.3), `nginx` (порт 8090), `db` (PostgreSQL 16, порт 5433),
`redis` (порт 6369), `scheduler` (планировщик), `node` (Vite с HMR, порт 5163) и
разовый `artisan` под профилем `tools`. В dev код примонтирован с хоста, а сборка
кэшей и OPcache без revalidate выключены — правки видны сразу.

### Вариант 2 — локально

```bash
composer setup     # install + .env + key:generate + migrate + npm install + npm run build
composer run dev   # сервер, обработчик очереди, логи (pail) и Vite одной командой
```

`composer setup` использует настройки БД из `.env`; по умолчанию это SQLite
(`database/database.sqlite`). Приложение будет доступно на http://localhost:8000.

---

## Деплой

Прод собирается тем же `docker/php/Dockerfile`, но другим набором файлов: код и
собранный фронтенд уезжают внутрь образа, `storage` становится именованным томом,
nginx получает копию `public/` отдельной стадией.

Боевой сервер: домен `arome-tm.com`, код в `/srv/projects/arome`, наружу торчит
nginx на 80-м порту по голому http.

```bash
cd /srv/projects/arome
cp .env.production.example .env.production
# заполнить APP_KEY, DB_PASSWORD, REDIS_PASSWORD, ADMIN_PASSWORD,
# SUPERADMIN_PASSWORD — шаблон приезжает с пустыми значениями;
# APP_URL уже стоит http://arome-tm.com

docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Оба `-f` обязательны. Без `docker-compose.prod.yml` поднимется базовый стек, где
`storage` живёт в слое контейнера — логи, бэкапы и загрузки исчезнут при первом же
`up --build`. `--env-file` нужен не только контейнерам: из того же файла Compose
подставляет `${DB_USERNAME}` и `${REDIS_PASSWORD}` в сервисы `db` и `redis`.

Ключ приложения генерируется до первого запуска:

```bash
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml run --rm artisan key:generate --show
```

На старте контейнер `php` сам догоняет схему, прогоняет сидер и собирает кэши
config/route/view/event. Планировщик крутится отдельным контейнером и раз в две
недели снимает дамп базы в `storage/app/backups` (том `storage_data`).

Разовые команды — через сервис `artisan` (профиль `tools`), он поднимается без
entrypoint'а и не трогает миграции:

```bash
docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml run --rm artisan db:backup
```

Наружу торчит только nginx на порту 80. TLS сейчас нет: `SESSION_SECURE_COOKIE`
в `.env.production` остаётся выключенным, иначе кука не долетит по http и вход
перестанет работать. Заголовки `X-Forwarded-*` nginx перебивает своими значениями
(`docker/nginx/conf.d/nginx.conf`) — без прокси перед ним клиентским доверять
нельзя. Когда TLS-терминатор появится: включить `SESSION_SECURE_COOKIE=true`,
перевести `APP_URL` на `https://` и вернуть в тех же строках `$http_x_forwarded_*`.

`docker-compose.override.yml` — dev-only (порт 8090, бинд-маунт кода, Vite) и в
репозиторий не едет, но Compose подхватывает его автоматически, если файл лежит
рядом. На сервере его быть не должно.

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
