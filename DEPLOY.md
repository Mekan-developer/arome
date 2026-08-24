# Деплой ARÔME Admin

Пошаговая инструкция для боевого сервера. Проверена на `ltmstudio`
(`/srv/projects/arome`, домен `arome-tm.com`).

Читать сверху вниз: шаги идут в том порядке, в котором их нужно выполнять.
Если что-то пошло не так — [Частые ошибки](#частые-ошибки) внизу, там разобран
каждый симптом, который реально встречался.

---

## Как устроен прод

```
браузер
   │  http://arome-tm.com  :80
   ▼
nginx на самом сервере            /etc/nginx/sites-available/arome-tm.com
   │  proxy_pass 127.0.0.1:8080
   ▼
контейнер arome_nginx             образ arome-nginx:local, копия public/ внутри
   │  fastcgi php:9000
   ▼
контейнер arome_app (php-fpm)     образ arome-php:local, код и vendor внутри
   │
   ├─► arome_db     PostgreSQL 16   том db_data
   ├─► arome_redis  Redis 8         том redis_data
   └─► том storage_data → /var/www/laravel/storage

контейнер arome_scheduler         тот же образ, php artisan schedule:work
```

Ключевое отличие от dev: **код и собранный фронтенд лежат внутри образа**, а не
монтируются с хоста. Значит любое изменение кода требует пересборки образа — по
одному только `git pull` на сервере ничего не поменяется.

Наружу торчит только 80-й порт хостового nginx. Контейнер слушает
`127.0.0.1:8080`, база и Redis портов на хост не отдают вовсе.

---

## Сокращение команд

Полная команда Compose в проде длинная и повторяется десятки раз. Заведи алиас:

```bash
echo "alias dc='docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml'" >> ~/.bashrc
source ~/.bashrc
```

Дальше в этом файле `dc` = эта команда целиком. Без алиаса подставляй её руками.

Три части алиаса обязательны, и вот почему:

| Часть | Зачем |
|---|---|
| `--env-file .env.production` | Не только для контейнеров: из этого же файла Compose подставляет `${DB_USERNAME}`, `${DB_PASSWORD}`, `${REDIS_PASSWORD}` в сервисы `db` и `redis`. Без флага возьмётся `.env`, которого на сервере нет — postgres поднимется с пустым пользователем. |
| `-f docker-compose.yml` | Базовый стек: описание всех сервисов. |
| `-f docker-compose.prod.yml` | Прод-оверлей: том `storage_data`, `.env.production`, порт только на localhost, ротация логов. Без него `storage` останется в слое контейнера — логи, бэкапы и загрузки исчезнут при первом же `up --build`. |

---

## Первый деплой с нуля

### Шаг 1. Код нужной ветки

```bash
cd /srv
git clone <url> projects/arome
cd /srv/projects/arome
git checkout <ветка>
```

**Сверь ветку и последний коммит:**

```bash
git branch --show-current
git log --oneline -3
```

Это самая частая причина «задеплоил, а ничего не изменилось». `origin/HEAD`
указывает на `main`, и свежий клон встаёт именно туда — а работа может вестись
в другой ветке. Собранный образ при этом будет честным и свежим, просто из
старого кода.

### Шаг 2. Файл окружения

```bash
cp .env.production.example .env.production
nano .env.production
```

Шаблон приезжает с пустыми паролями. Заполнить обязательно:

| Переменная | Что это |
|---|---|
| `DB_PASSWORD` | Пароль postgres. Задаётся при **первом** создании тома `db_data` и потом внутри базы не меняется — если поменять его в файле позже, приложение перестанет подключаться. |
| `REDIS_PASSWORD` | Пароль Redis. |
| `ADMIN_PASSWORD` | Пароль главного администратора панели. |
| `SUPERADMIN_PASSWORD` | Пароль служебной консоли `/su`. |

`APP_KEY` пока оставь пустым — он генерируется на шаге 4.

`APP_URL` уже стоит `http://arome-tm.com`. `SESSION_SECURE_COOKIE` должен
остаться закомментированным: сайт работает по голому http, и с secure-кукой
вход не сработает вообще.

### Шаг 3. Сборка образов

```bash
dc build
```

Собираются два образа из одного `docker/php/Dockerfile`: `arome-php:local`
(стадия `app`) и `arome-nginx:local` (стадия `web`).

Обе внешние зависимости заведены на зеркала, потому что напрямую наружу сервер
не пускают — соединения отбиваются по TCP RST:

- **npm** → `https://nexus.telecom.tm/repository/npm-proxy/`, дефолт в `ARG NPM_REGISTRY`.
  Ссылки на тарболлы внутри `package-lock.json` npm переписывает на этот же хост сам,
  лок трогать не нужно.
- **apt** → `https://mirror.yandex.ru`, дефолт в `ARG DEBIAN_MIRROR`.

Там, где есть прямой доступ в интернет, дефолты перебиваются:

```bash
dc build --build-arg NPM_REGISTRY=https://registry.npmjs.org/ \
         --build-arg DEBIAN_MIRROR=https://deb.debian.org
```

Composer ходит в `repo.packagist.org` и `codeload.github.com` напрямую — если
однажды и их перекроют, лечится тем же приёмом через composer-прокси Nexus.

### Шаг 4. Ключ приложения

```bash
dc run --rm artisan key:generate --show
```

Команда только печатает ключ, в файл ничего не пишет. Скопируй вывод
(`base64:...`) в `APP_KEY=` в `.env.production`.

**Не пропускай этот шаг.** Без ключа контейнер стартует нормально — миграции,
сидер и кэш конфига шифровальщик не трогают — и валится только на HTTP-запросах,
отдавая 500 без внятного текста.

### Шаг 5. Запуск

```bash
dc up -d
```

Дальше контейнер `php` всё делает сам на старте (`docker/php/entrypoint.sh`):
досоздаёт каталоги `storage`, гоняет `migrate --force` с 10 попытками (ждёт, пока
поднимется база), прогоняет сидер и собирает кэши config/route/view/event.
Отдельно ничего запускать не надо.

Миграции выполняет только `php`. Планировщик работает с той же базой, и
параллельный `migrate` ловил бы блокировку — поэтому `RUN_MIGRATIONS=true` стоит
у одного сервиса.

### Шаг 6. Проверка контейнеров

```bash
dc ps                      # все сервисы должны быть Up, db и redis — healthy
curl -I http://127.0.0.1:8080
```

Ожидаемый ответ — `302 Found` с `Location: /products` и двумя `Set-Cookie`.
Зашифрованные куки в ответе означают, что `APP_KEY` встал правильно.

Если что-то не так — логи:

```bash
dc logs --tail=80 php
```

`LOG_CHANNEL=stderr`, поэтому ошибки Laravel идут прямо в лог контейнера, а не
в файл внутри тома.

### Шаг 7. Хостовый nginx

```bash
sudo cp /srv/projects/arome/docker/nginx/host/arome-tm.com.conf \
        /etc/nginx/sites-available/arome-tm.com
sudo ln -s /etc/nginx/sites-available/arome-tm.com /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl enable --now nginx
```

`enable --now` вместо `reload`: на чистом сервере nginx часто установлен, но не
запущен, и `reload` тогда ругается `nginx.service is not active`. `enable`
заодно ставит автозапуск после перезагрузки сервера.

Лимиты в конфиге (`client_max_body_size 50M`, `proxy_read_timeout 180s`)
выставлены вровень с контейнерным nginx и `docker/php/php.ini`. Режет по
меньшему из трёх, и обрыв на хосте выглядит как 413 или 504 без единой строки в
логах Laravel — поэтому менять их надо во всех трёх местах сразу.

### Шаг 8. Проверка снаружи

```bash
curl -I -H "Host: arome-tm.com" http://127.0.0.1     # не зависит от DNS
dig +short arome-tm.com                              # A-запись → IP сервера
curl -s ifconfig.me                                  # внешний IP сервера
```

Первая команда должна дать тот же `302 → /products`, что и с порта 8080.

В firewall открыт только 80-й. Порт 8080 наружу открывать **не нужно** — он
специально привязан к `127.0.0.1`, чтобы панель нельзя было дёрнуть по
`http://IP:8080` в обход прокси.

### Шаг 9. Учётные записи

Главный администратор уже создан сидером — вход по `ADMIN_LOGIN` /
`ADMIN_PASSWORD` из `.env.production`.

Суперадмин для консоли `/su` сам не заводится, нужна разовая команда:

```bash
dc run --rm artisan aroma:superadmin
```

---

## Обновление до новой версии

```bash
cd /srv/projects/arome

dc run --rm artisan db:backup     # 1. бэкап перед миграциями

git fetch origin                  # 2. свежий код нужной ветки
git checkout <ветка>
git pull origin <ветка>
git log --oneline -3              #    сверить, что приехало ожидаемое

dc up -d --build                  # 3. пересборка и перезапуск
dc logs --tail=50 php             # 4. проверить, что миграции прошли
curl -I http://127.0.0.1:8080
```

`--build` обязателен: код лежит внутри образа, без пересборки контейнер
поднимется на старой версии. Пересобираются оба образа — и `php`, и `nginx`
(в нём копия `public/` с собранным фронтендом).

Новые миграции entrypoint прогонит сам при старте. Бэкап перед этим — на случай
если миграция окажется необратимой.

### Если поменялся только `.env.production`

```bash
dc up -d --force-recreate php scheduler
```

Здесь `--force-recreate` обязателен. Образ не менялся, поэтому обычный `up -d`
сочтёт контейнеры актуальными и не тронет их, а `config:cache` собирается на
старте — контейнер продолжит жить со старым закэшированным конфигом.

---

## Частые ошибки

### `500 Internal Server Error`, в логе `MissingAppKeyException`

Пустой `APP_KEY`. Трейс обрывается на сборке `Illuminate\Cookie\Middleware\EncryptCookies` —
ему нужен `Encrypter`, а собрать его без ключа нельзя.

Лечение: [Шаг 4](#шаг-4-ключ-приложения), затем `dc up -d --force-recreate php scheduler`.

### `npm ci` падает: `connect to 104.16.x.x port 443 failed: Connection refused`

Сервер не пускают в `registry.npmjs.org` (адреса Cloudflare). Отбой мгновенный,
по TCP RST — это не таймаут и не DNS, имя резолвится нормально.

Лечится реестром Nexus, он уже прописан дефолтом в `ARG NPM_REGISTRY`. Если
ошибка всё же вылезла — проверь, доступен ли сам Nexus:

```bash
curl -sI --max-time 10 https://nexus.telecom.tm/repository/npm-proxy/
```

### `nginx.service is not active, cannot reload`

Конфиг валиден, но nginx на хосте не запущен — перезагружать нечего.

```bash
sudo systemctl enable --now nginx
sudo ss -tlnp | grep ':80 '     # если старт упал — кто-то другой держит порт
```

### Задеплоил, но UI остался старым

Почти всегда — не та ветка на сервере. Проверь:

```bash
git branch --show-current
git log --oneline -3
```

Свежий клон встаёт на `main` (туда указывает `origin/HEAD`), а работа может
вестись в другой ветке. Второй по частоте вариант — забыт `--build`.

Кэш браузера тут почти никогда не при чём: имена файлов Vite содержат хэш
содержимого и меняются при каждой сборке.

### Открывается дефолтная страница nginx

Запрос ушёл в `default_server`. Отключи дефолтный сайт:

```bash
sudo rm /etc/nginx/sites-enabled/default
sudo systemctl reload nginx
```

### `502 Bad Gateway`

Контейнер `php` не отвечает — упал или ещё стартует.

```bash
dc ps
dc logs --tail=80 php
```

Частый случай на первом запуске: база не поднялась, entrypoint отработал 10
попыток `migrate` и вышел с ошибкой.

### Postgres ругается на пустого пользователя

Забыт `--env-file .env.production` — Compose не смог подставить `${DB_USERNAME}`.
Если том `db_data` уже создан с пустыми значениями, его придётся удалить:

```bash
dc down
docker volume rm arome_db_data     # ВНИМАНИЕ: удаляет базу целиком
dc up -d
```

---

## Полезные команды

```bash
dc ps                                  # состояние сервисов
dc logs -f php                         # живой лог приложения
dc logs --tail=100 nginx               # лог контейнерного nginx
dc restart php scheduler               # перезапуск без пересборки
dc down                                # остановить всё (тома остаются)

dc run --rm artisan db:backup          # дамп базы в storage/app/backups
dc run --rm artisan aroma:superadmin   # доступ к консоли /su
dc run --rm artisan migrate:status     # что уже прогнано
dc run --rm artisan tinker

docker compose --env-file .env.production \
  -f docker-compose.yml -f docker-compose.prod.yml exec php sh    # шелл внутри контейнера
```

Разовые команды идут через сервис `artisan` (профиль `tools`): у него подменён
entrypoint, так что миграции и сборку кэшей он не трогает.

---

## О чём важно помнить

- **Пароль администратора освежается при каждом рестарте** из `.env.production`.
  Менять его надо в файле, а не в панели — иначе смена откатится на следующем
  `up`. Так же и с `SUPERADMIN_PASSWORD`.

- **`storage` живёт в томе `storage_data`** и переживает пересборку образов. Там
  же лежат загрузки, логи и бэкапы. `docker compose down -v` сотрёт его вместе с
  базой — этот флаг на проде не использовать.

- **Бэкапы делает планировщик** — 1-го и 16-го числа в 03:00, в
  `storage/app/backups`, хранит последние `BACKUP_KEEP` копий (по умолчанию 10).
  Файлы внутри тома, так что для внешнего хранения их надо выгружать отдельно.

- **`docker-compose.override.yml` на сервере быть не должно.** Это dev-оверлей
  (бинд-маунт кода, Vite, порт 8090); он в `.gitignore` и не приезжает с
  `git pull`. Compose подхватывает его автоматически, если файл лежит рядом, —
  но только когда `-f` не указаны явно.

- **TLS пока нет.** Когда появится терминатор: включить `SESSION_SECURE_COOKIE=true`,
  перевести `APP_URL` на `https://` и заменить `$scheme` на
  `$http_x_forwarded_proto` в строке `HTTP_X_FORWARDED_PROTO` в
  `docker/nginx/conf.d/nginx.conf`.
