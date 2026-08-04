# Промпт для Claude Code — «ARÔME Admin»

> Как пользоваться: создай пустой проект `laravel new my-app`, положи этот файл в корень как `AROMA-ADMIN-PROMPT.md`, положи логотип в `public/img/arome-logo.png` и запусти Claude Code с сообщением:
> **«Используй скилл crm-builder. Прочитай AROMA-ADMIN-PROMPT.md и реализуй всё, что там описано, полностью. Не упрощай, не заменяй компоненты на UI-библиотеки.»**

---

## 0. ПЕРВОЕ ДЕЙСТВИЕ — подключить скилл `crm-builder`

**Прежде чем написать хоть одну строку кода, активируй скилл `crm-builder` и прочитай его референсы:**

```
references/architecture.md      — слои, Form Request, Policy, Pest
references/data-and-api.md      — серверные операции над данными
references/performance.md       — индексы, pg_trgm, курсорная пагинация
references/api.md               — версионирование /api/v1
references/frontend-vue.md      — структура каталогов, фильтры через URL, useForm
references/craft-baseline.md    — планка ремесла, чек-лист перед сдачей
references/ui-directions.md     — антипаттерны (прогнать токены из §2)
assets/design-log.md            — журнал направлений
```

Это CRM/админ-панель — скилл `crm-builder` применяется обязательно и в полном объёме. Дальше — как этот файл ложится на его шаги.

### Соответствие шагам скилла

| Шаг скилла | Статус |
|---|---|
| **Шаг 1. Бриф** | **Выполнен, вопросы не задавать.** Домен: розничная сеть парфюмерии в Туркменистане (ARÔME). За системой сидит администратор сети за большим монитором; продавцы в системе не работают — только мобильное приложение со сканером, получающее данные по API. Сценарии: (1) найти товар по штрихкоду/артикулу, (2) поправить цену и скидку — точечно и массово, (3) залить прайс из Excel и разобрать ошибки строк, (4) выдать/забрать доступ сотруднику, (5) настроить, какие поля карточки уходят продавцу. Главная сущность — **товар (SKU)**. Объём: 5 000 SKU, прайсы по 1 800–1 900 строк. |
| **Шаг 2. Дизайн-направление** | **Выполнен, направление зафиксировано — не выбирать своё, не «улучшать».** Токены выписаны ниже. |
| **Шаг 3. Проверка на шаблонность** | Прогони токены §2 по разделу «Антипаттерны» из `ui-directions.md`. Расхождения — только в мою сторону: спецификация побеждает. Отчитайся списком, что проверил. |
| **Шаг 4. Backend** | По скиллу целиком: Route → Controller → Service → Repository, Form Request, Enum, Policy, индексы, Pest. Детали домена — §3, §14. |
| **Шаг 5. Frontend** | По `frontend-vue.md` + `craft-baseline.md`, но визуал — строго §2–§13 этого файла. |
| **Шаг 6. Журнал** | Допиши строку в `assets/design-log.md` (готовый текст — §16). |

### Токены направления (Шаг 2 скилла, заполнено)

```
Домен:        розничная сеть парфюмерии, каталог и цены за прилавком
Направление:  «прайс-лист на бумаге» — тёплая бумага, волосяные линейки,
              моноширинные цифры, никаких карточек и теней
Палитра:      #1B1512 чернила, #E9E1D3 бумага, #FAF6ED лист, #A2751E латунь,
              #93251C тревога, #4E6A46 норма
Шрифты:       display — Playfair Display, body — IBM Plex Sans, data — IBM Plex Mono
Сетка:        без сайдбара — горизонтальные табы 40px под шапкой 56px;
              таблица во всю ширину + модалка карточки 660px
Радиусы:      2px только у полей и кнопок, всё остальное — прямые углы
Сигнатура:    строка товара как строка накладной — цветная полоса категории
              8px слева на всю высоту, цена перечёркнута при скидке,
              статус подчёркнут снизу 2px вместо «пилюли»
```

---

## 0.1 Роль и жёсткие запреты

Ты — фронтенд-инженер и дизайн-инженер. Задача: собрать админ-панель **ARÔME Admin** на Laravel + Inertia + Vue 3 **пиксель-в-пиксель** по спецификации ниже.

**ЗАПРЕЩЕНО:**
- Bootstrap, Vuetify, PrimeVue, shadcn, Element, Naive UI и любые готовые UI-киты. Только собственные компоненты.
- **Дефолтная палитра Tailwind.** Tailwind разрешён (стек скилла), но исключительно как утилиты раскладки и через токены. `bg-slate-*`, `text-gray-*`, `bg-blue-500`, `rounded-lg`, `shadow-md`, любой `#hex` в шаблоне — ошибка. Цвета, шрифты и радиусы приходят только из `resources/css/tokens.css` (§2), подключённого в `tailwind.config.js` через `theme.extend`.
- Скруглённые карточки, «мягкие» тени, градиенты, «стеклянные» панели, эмодзи, иконочные шрифты, SVG-иллюстрации.
- Менять цвета, шрифты, размеры шрифтов, отступы и grid-шаблоны из спецификации. Все числа ниже — точные.
- Иконки-картинки. Все «иконки» в интерфейсе — это типографика: `‖|‖`, `→`, `←`, `✓`, `·`, `↑`, `↓`, `!`.
- Менять русские/туркменские тексты. Копирайт — часть дизайна, переписывать нельзя.

**ЖЁСТКИЕ ПРАВИЛА ВИЗУАЛА (проверяй на каждом экране):**
1. `border-radius` бывает только `2px` — у инпутов, селектов, кнопок. Всё остальное — прямые углы.
2. Тени существуют только у модалок и выезжающей панели. Больше нигде.
3. Разделители — волосяные линии `1px solid var(--rule-soft)` внутри таблиц, `1px solid var(--rule-strong)` между зонами.
4. Все числа (цены, остатки, даты, версии, коды) — `font-family: var(--f-data)` + `font-variant-numeric: tabular-nums`.
5. Микро-заголовки — `var(--f-data)`, 9–10.5px, `letter-spacing: .13–.18em`, `text-transform: uppercase`, цвет `--ink-3` или `--brass-dark`.
6. Крупные заголовки — `var(--f-display)` (Playfair Display), `line-height: 1.2–1.25`.
7. Статус — не «пилюля». Это текст `var(--f-data)` 9.5px + `border-bottom: 2px solid <цвет статуса>` + `padding-bottom: 2px`.
8. Базовый размер текста интерфейса — `14px`, в таблицах 12–13.5px.
9. Кнопка-действие: `background: var(--ink)`, `color: var(--ink-inv)`, `border: 0`, hover → `background: var(--brass-dark)`.
   Кнопка-призрак: `background: transparent`, `border: 1px solid var(--rule-strong)`, `color: var(--ink-2)`, hover → `border-color: var(--brass); color: var(--ink)`.
10. Оверлей модалки: `rgba(27,21,18,.42)`, у подтверждений `rgba(27,21,18,.46)`.
11. Длинные тексты — `text-wrap: pretty`. Однострочные ячейки — `overflow:hidden; text-overflow:ellipsis; white-space:nowrap`.
12. Никакого скролла у `body`. Приложение — `height: 100vh; overflow: hidden`, скроллятся только тела таблиц.

---

## 1. Стек и установка

Стек фиксирован скиллом: **Laravel + Inertia + Vue 3 `<script setup>` + Tailwind.** Не предлагать Nuxt, отдельное SPA или API-клиент на фронте.

```bash
composer require inertiajs/inertia-laravel
php artisan inertia:middleware        # зарегистрировать HandleInertiaRequests в web
npm i @inertiajs/vue3 vue @vitejs/plugin-vue tailwindcss @tailwindcss/vite
```

- `resources/views/app.blade.php` — единственный blade-шаблон, в нём `@inertia`, `@vite`, подключение шрифтов Google и `<link rel="preconnect">`.
- `resources/js/app.js` — `createInertiaApp` + `createApp` + `resolvePageComponent`.
- `vite.config.js` — плагин `vue()` c `template.transformAssetUrls.base = null`.
- Никакого TypeScript, никакого Pinia, никакого vue-router. Навигация — только Inertia `router.get` / `<Link>`.
- На клиенте живёт только состояние интерфейса (что открыто, что выделено, что набрано). Всё остальное — с сервера.

### Два правила скилла, которые не обсуждаются

1. **Операции над данными — только на сервере.** Поиск по штрихкоду/артикулу/названию, фильтры «Точка» и «Статус», сортировка, пагинация по 25, счётчики в мастере импорта — всё запросом на сервер. `props.products.filter(...)` в `computed` — ошибка: в каталоге 5 000 SKU. Фильтры живут в URL, подгрузка — частичная (`router.get(..., { only: ['products'], preserveState: true })`). Поля, скрытые матрицей прав, не должны вообще приходить в ответе, а не прятаться на экране.
2. **API версионируется с первого дня.** У системы есть второй потребитель — мобильное приложение продавца со сканером. Значит `routes/api/v1.php`, `app/Http/Controllers/Api/V1/`, `app/Http/Resources/V1/` сразу. Сервисы общие для веба и API. Эндпоинты: `GET /api/v1/products` (с учётом матрицы прав роли), `GET /api/v1/products/{barcode}`, `POST /api/v1/sync`.

### Структура

```
app/Http/Controllers/  AuthController, ProductController, ImportController,
                       UserController, RightsController, PointController,
                       DeviceController, AuditController, SuperadminController
app/Http/Controllers/Api/V1/  ProductApiController, SyncApiController
app/Http/Resources/V1/        ProductResource (уважает role_field_rights)
app/Services/          ProductService, ImportService, PasswordService, ModuleService, RightsService
app/Repositories/      ProductRepository, UserRepository, DeviceRepository, AuditRepository
app/Http/Requests/     StoreProductRequest, UpdateProductRequest, BulkPriceRequest,
                       StoreUserRequest, ChangePasswordRequest, UpdateRightsRequest
app/Enums/             ProductStatus, UserRole, AuditKind, ImportIssueKind, ModuleKey
app/Policies/          ProductPolicy, UserPolicy, ModulePolicy (только superadmin)
resources/css/tokens.css  — все цвета/шрифты проекта (§2), больше нигде
resources/css/app.css     — @import tokens.css + @tailwind + базовые сбросы
resources/js/Layouts/  AdminLayout.vue, AuthLayout.vue, SuLayout.vue
resources/js/Pages/    Login.vue, Products/Index.vue, Import/Index.vue, Users/Index.vue,
                       Rights/Index.vue, Points/Index.vue, Devices/Index.vue,
                       Audit/Index.vue, Su/Index.vue
resources/js/Components/  (список в §5)
```

Компоненты именуются под домен, а не абстрактно: `ProductRow`, `PriceCell`, `ImportIssueRow`, `StaffAccessButton` — не `DataTableRow` и не `TableCell`.

Каждый контроллер — тонкий: валидация через Form Request → вызов сервиса → `Inertia::render` с DTO/Resource (не голые модели). Бизнес-логика (расчёт цены со скидкой, разбор Excel, генерация пароля, EAN-13, каскад модулей) — в сервисах. Запросы к БД — в репозиториях. Статусы — Enum, не строки. Права — Policy, не проверки в контроллере.

**Скорость — требование, а не оптимизация под конец.** Сидер наполняет каталог до 50 000 строк для проверки (214 демо-товаров из §3 — видимая часть, остальное — нагрузочный факторинг в отдельном сидере). Индексы на `sku`, `barcode`, `main_code`, `status`, `name` (триграммный через `pg_trgm`), на все внешние ключи и на `product_stocks(product_id, point_id)`. Поиск по названию — через `pg_trgm`, не `LIKE '%…%'`. Разбор Excel — в очереди.

---

## 2. Дизайн-система

`resources/css/tokens.css` — единственный источник цвета и шрифта в проекте, скопируй дословно:

```css
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap');

:root{
  --ink:#1B1512; --ink-2:#3E3229; --ink-3:#7A6A5B; --ink-inv:#F6F1E6;
  --paper:#E9E1D3; --sheet:#FAF6ED; --sheet-alt:#F4EEE1; --sheet-hi:#F0E6D2;
  --rule:#D5C9B4; --rule-soft:#E5DBC8; --rule-strong:#B9A98C;
  --brass:#A2751E; --brass-dark:#6E4E10; --brass-tint:#EFE2C4;
  --danger:#93251C; --danger-tint:#F3DDD8; --ok:#4E6A46; --warn:#B07A16;
  --c-parfum:#5A2A0E; --c-edp:#8A3B12; --c-edt:#B4761F; --c-edc:#C9A96A;
  --f-display:'Playfair Display',Georgia,serif;
  --f-body:'IBM Plex Sans',system-ui,sans-serif;
  --f-data:'IBM Plex Mono',ui-monospace,monospace;
}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font-family:var(--f-body);-webkit-font-smoothing:antialiased}
input,select,button{font-family:inherit;font-size:inherit;color:inherit}
a{color:var(--brass-dark);text-decoration:none;border-bottom:1px solid var(--rule)}
a:hover{color:var(--ink);border-bottom-color:var(--brass)}
@keyframes slidein{from{transform:translateX(24px);opacity:0}to{transform:translateX(0);opacity:1}}
```

`tailwind.config.js` — пробрось токены в тему, чтобы в шаблонах писали `bg-ink`, `text-ink-3`, `border-rule-strong`, `font-data`, а не дефолтную палитру:

```js
theme: { extend: {
  colors: {
    ink:{DEFAULT:'var(--ink)',2:'var(--ink-2)',3:'var(--ink-3)',inv:'var(--ink-inv)'},
    paper:'var(--paper)', sheet:{DEFAULT:'var(--sheet)',alt:'var(--sheet-alt)',hi:'var(--sheet-hi)'},
    rule:{DEFAULT:'var(--rule)',soft:'var(--rule-soft)',strong:'var(--rule-strong)'},
    brass:{DEFAULT:'var(--brass)',dark:'var(--brass-dark)',tint:'var(--brass-tint)'},
    danger:{DEFAULT:'var(--danger)',tint:'var(--danger-tint)'}, ok:'var(--ok)', warn:'var(--warn)',
  },
  fontFamily:{display:'var(--f-display)', body:'var(--f-body)', data:'var(--f-data)'},
  borderRadius:{DEFAULT:'2px'},
}}
```

Граница между Tailwind и `style`: раскладка, отступы, цвет, типографика — классами Tailwind по токенам. Точные `grid-template-columns` из этого файла и дробные размеры шрифта (12.5px, 9.5px, 13.5px) — через `style` или арбитрарные значения `text-[12.5px]`. Главное — ни одного цвета мимо токенов.

**Тёмные поверхности** (шапка, тёмная колонка логина, шапки модалок) используют дополнительно:
`#241D18` (фон поля поиска), `#3E3229` (границы на тёмном), `#A99A88` (вторичный текст), `#8C7B69` (третичный), `#150E0C` (шапка служебной консоли), `#2A1613` + `#D08C7E` + `#E6BFA6` (полоса «просмотр глазами администратора»).

**Цветовая полоска категории** (левый край строки товара, 8px): PARFUM → `--c-parfum`, EDP → `--c-edp`, EDT → `--c-edt`, CARE → `--c-edc`.

**Цвета статусов:** `active` → `--ok`, `hidden` → `--ink-3`, `archived` → `--danger`.
**Подписи статусов:** `В ПРОДАЖЕ` / `СКРЫТ` / `АРХИВ`.

**Формат чисел:** `toLocaleString('ru-RU')` для целых, `{minimumFractionDigits:2, maximumFractionDigits:2}` для денег. Разделитель дробной части — запятая, тысяч — неразрывный пробел. Валюта всегда `TMT`.

---

## 3. Модель данных, миграции, сидеры

Миграции + модели + фабрики + сидер `DemoSeeder`, всё наполняется данными ниже.

| Таблица | Поля |
|---|---|
| `points` | id, code (БРК/ГЛС/М30/СКЛ), name, address, is_warehouse (bool), is_active |
| `products` | id, main_code (AA1001…), sku, barcode (EAN-13), name, kind (PARFUM/EDP/EDT/CARE), price decimal(10,2), discount decimal(5,4), status enum(active,hidden,archived) |
| `product_stocks` | product_id, point_id, qty |
| `price_histories` | product_id, changed_at, author, price_from, price_to |
| `users` | id, name, login (unique), role enum(admin,seller,superadmin), password, is_active, last_login_at, device |
| `point_user` | user_id, point_id |
| `role_field_rights` | role, field (mainCode/name/sku/barcode/stock/retail/discount), visible bool |
| `devices` | user_id, point_id, model, app_version, synced_at, data_version, lag, is_blocked |
| `audit_logs` | happened_at, actor, action, object, value_from, value_to, kind |
| `import_batches` | file_name, imported_at, rows_ok, rows_failed |
| `modules` | key, is_enabled, depends_on |

### Точки

```
БРК · Беркарар      · ТЦ Беркарар, 1 этаж      · торговая
ГЛС · Гүлүстан      · Русский базар, пав. 14   · торговая
М30 · 30-й мкр      · ул. Ататюрк, 82          · торговая
СКЛ · Склад Чоганлы · Чоганлы, склад 4         · склад
```
SKU в наличии по точкам: 1 842 / 1 519 / 1 204 / 3 760. Сотрудников: 3 / 2 / 1 / 2.

### Каталог — 214 товаров, генерируется детерминированно

Псевдослучайный генератор (seed 41), чтобы каталог был одинаковым при каждом сидировании:

```php
// mulberry32
$seed = 41;
$rnd = function () use (&$seed) {
    $seed = ($seed + 0x6D2B79F5) & 0xFFFFFFFF;
    $t = $seed;
    $t = (($t ^ ($t >> 15)) * (1 | $t)) & 0xFFFFFFFF;
    $t = ($t + ((($t ^ ($t >> 7)) * (61 | $t)) & 0xFFFFFFFF)) & 0xFFFFFFFF;
    return ((($t ^ ($t >> 14)) & 0xFFFFFFFF) >>> 0) / 4294967296;
};
```

Бренд+линия (выбор случайный): VERSACE BRIGHT CRYSTAL / VERSACE EROS FLAME / VERSACE DYLAN BLUE / ARMANI ACQUA DI GIO / ARMANI SI PASSIONE / DOLCE&GABBANA LIGHT BLUE / HUGO BOSS BOTTLED / CALVIN KLEIN ETERNITY / LANCOME LA VIE EST BELLE / PACO RABANNE INVICTUS / LATTAFA KHAMRAH / ARMAF CLUB DE NUIT.

Форма и объёмы: `EDT [30,50,90,100]`, `EDP [30,50,90,100]`, `PARFUM [15,30,50]`, `DEODORANT [50,150]`, `DEOSTICK [50,75]`, `BATH&SHOWER GEL [200,250]`, `BODY LOTION [200]`. Первые три дают kind = EDT/EDP/PARFUM, остальные — CARE.

Для товара `i` (0…213):
- `main_code = 'AA' . (1001 + i)`, `sku = (510000 + i*13)`, `barcode = ean13('801100399' . (3800 + i*7))`
- `name = "{BRAND} {LINE} {FORM} {VOL}ML"` (верхний регистр)
- `price = round((180 + rnd()*2400), 2)`
- `discount`: с вероятностью 22 % одно из `0.1 0.15 0.2 0.3 0.5`, иначе 0
- `status` из массива `[active, active, active, active, active, hidden]` по `rnd()`
- остаток по каждой точке: `rnd()<0.16 → 0`, `<0.34 → 1..3`, иначе `3..28`

Контрольная сумма EAN-13: берём первые 12 цифр (дополняя нулями слева), сумма `d[i]*(i%2 ? 3 : 1)`, контрольная = `(10 - sum%10) % 10`.

### История цены (для карточки товара)

```
18.07.2026 · Айнур Д. · импорт Excel   · 385 → 420 (рост)
02.06.2026 · Мерет А. · вручную        · 420 → 385 (падение)
11.04.2026 · Айнур Д. · импорт Excel   · 360 → 420 (рост)
27.01.2026 · Система · переоценка курса · 340 → 360 (рост)
```

### Сотрудники

```
1 Айнур Дурдыева    aynur   Администратор БРК,ГЛС,М30,СКЛ 28.07.2026 14:06 Веб                     активен
2 Мерет Аннаев      meret   Администратор БРК,ГЛС         28.07.2026 11:42 Веб                     активен
3 Гөзел Сапарова    gozel   Продавец      БРК             28.07.2026 13:58 Redmi 12 · v2.4.1       активен
4 Бегенч Ходжаев    begenc  Продавец      ГЛС             27.07.2026 20:15 Samsung A15 · v2.4.1    активен
5 Огулджан Мурадова oguljan Продавец      М30             28.07.2026 09:31 Infinix Hot 30 · v2.3.8 активен
6 Сапар Реджепов    sapar   Продавец      БРК,М30         24.07.2026 18:02 Redmi 10C · v2.3.8      ЗАБЛОКИРОВАН
7 Джемал Оразова    jemal   Продавец      ГЛС             26.07.2026 12:20 Tecno Spark · v2.4.1    активен
8 Керим Атаев       kerim   Администратор СКЛ             28.07.2026 08:10 Веб                     активен
```

### Устройства (версия каталога на сервере — 4193)

```
Гөзел Сапарова    БРК Redmi 12         2.4.1 28.07.2026 13:58 14 минут назад 4193 lag 0
Бегенч Ходжаев    ГЛС Samsung A15      2.4.1 27.07.2026 20:15 18 часов назад 4193 lag 0
Огулджан Мурадова М30 Infinix Hot 30   2.3.8 28.07.2026 09:31 5 часов назад  4102 lag 91
Джемал Оразова    ГЛС Tecno Spark      2.4.1 26.07.2026 12:20 2 дня назад    4090 lag 103
Сапар Реджепов    БРК Redmi 10C        2.3.8 24.07.2026 18:02 4 дня назад    3988 lag 205 blocked
Керим Атаев       СКЛ Планшет склада   2.4.1 28.07.2026 08:10 6 часов назад  4193 lag 0
```
Уровень: `blocked` → красный, `lag = 0` → `--ok`, `lag < 100` → `--warn`, иначе `--danger`.
Подпись: blocked → «доступ заблокирован, сессия завершена», ok → «данные актуальны», иначе «нужна синхронизация».
Отставание: `lag = 0` → «нет», иначе `−{lag} ревизий`.

### Журнал действий

```
28.07.2026 14:06 Айнур Д. Вход в панель            —                                          kind=auth
28.07.2026 13:44 Мерет А. Изменена цена            AR-1042 · Lattafa Khamrah    385,00→420,00 kind=price
28.07.2026 13:41 Мерет А. Изменён остаток          AR-1042 · Беркарар           9→12          kind=stock
28.07.2026 12:55 Айнур Д. Импорт из Excel          Price list 24.07.xlsx        →1 894 строки kind=import
28.07.2026 11:20 Айнур Д. Права роли изменены      Продавец · Опт / себестоимость видно→скрыто kind=rights
27.07.2026 19:02 Керим А. Товар скрыт              AR-1355 · Shaghaf Oud   в продаже→скрыт    kind=product
24.07.2026 18:40 Айнур Д. Сотрудник заблокирован   sapar · Сапар Реджепов  активен→заблокирован kind=user
24.07.2026 18:40 Система  Сессия завершена         Redmi 10C · токен отозван                  kind=auth
24.07.2026 10:11 Мерет А. Создан товар             AR-9004 · Supremacy Not Only →410,00       kind=product
23.07.2026 16:35 Айнур Д. Добавлена точка          30-й мкр · ул. Ататюрк, 82   →активна      kind=point
```
Цвета типа события: price → `--brass-dark`, stock → `--ink-2`, rights → `--danger`, user → `--danger`, import → `--ok`, product → `--ink-2`, auth → `--ink-3`, point → `--ok`.

---

## 4. Модули (feature flags)

Таблица `modules`, управляется только из служебной консоли (§13). Выключенный модуль убирает из панели админа разделы, фильтры, колонки и поля форм — данные в БД остаются.

| key | Название | зависит от | значение по умолчанию |
|---|---|---|---|
| `points` | Точки продаж | — | **выключен** |
| `warehouses` | Склады | points | **выключен** |
| `productPoints` | Остатки товара по точкам | points | **выключен** |
| `import` | Импорт из Excel | — | включён |
| `devices` | Синхронизация устройств | — | включён |
| `audit` | Журнал действий | — | включён |

Эффективное состояние: модуль включён, только если включён он сам **и** рекурсивно все его зависимости.

Описания и «что затрагивает» (выводятся в консоли дословно):

- **Точки продаж** — «Сеть ведётся как несколько торговых точек: свой адрес, свой персонал, свой остаток. Выключено — администратор работает с одним общим каталогом.» Затрагивает: `Раздел «Точки и склады»`, `Фильтр «Точка» в товарах`, `Колонка «Точки» у сотрудников`, `Выбор точек в карточке сотрудника`, `Точка в синхронизации`. В базе: `4 точки, 9 привязок сотрудников`.
- **Склады** — «Склад — точка без продаж: участвует в остатках, но не в кассе. Выключено — в списках остаются только торговые точки.» Затрагивает: `Строка «Склад Чоганлы»`, `Колонка СКЛ в остатках`, `Статус «СКЛАД»`. В базе: `1 склад, 3 760 SKU на остатке`.
- **Остатки товара по точкам** — «Карточка товара хранит остаток отдельно по каждой точке. Выключено — у товара один общий остаток по сети.» Затрагивает: `Колонки БРК / ГЛС / М30 в таблице`, `Блок «Остаток по точкам» в карточке`, `Стартовые остатки в новом товаре`, `Действие «Перевести на точку»`. В базе: `12 480 записей остатков`.
- **Импорт из Excel** — «Загрузка прайса файлом с сопоставлением колонок и проверкой строк.» Затрагивает: `Раздел «Импорт»`, `Кнопка «Импорт из Excel» в товарах`. В базе: `18 загрузок в истории`.
- **Синхронизация устройств** — «Контроль того, какие телефоны продавцов давно не получали актуальный каталог.» Затрагивает: `Раздел «Синхронизация»`. В базе: `6 устройств`.
- **Журнал действий** — «Неизменяемая история действий в панели. Запись ведётся в любом случае — флаг скрывает только раздел.» Затрагивает: `Раздел «Журнал действий»`. В базе: `24 месяца записей`.

Состояние модулей отдаётся во все Inertia-страницы через `HandleInertiaRequests::share()` как `modules: { points: bool, ... }` (уже с учётом зависимостей).

---

## 5. Общие компоненты (`resources/js/Components/`)

Собери их до экранов, все экраны используют только их:

| Компонент | Что делает |
|---|---|
| `AppButton.vue` | `variant="solid" \| "ghost" \| "danger"`, `size="md" \| "sm" \| "xs"`. solid: `--ink`→hover `--brass-dark`. ghost: прозрачный + `1px solid var(--rule-strong)`→hover `--brass`. danger: `1px solid var(--danger)`, текст `--danger`. Всегда `border-radius:2px`, `white-space:nowrap`. |
| `FieldLabel.vue` | Микро-подпись: `var(--f-data)`, 9px, `letter-spacing:.15em`, uppercase, `color:var(--ink-3)`, `margin-bottom:5px`. |
| `TextField.vue` | `width:100%; padding:10px 12px; background:#fff; border:1px solid var(--rule-strong); border-radius:2px; font-size:14px; outline-offset:2px`. Проп `mono` → `var(--f-data)` + `tabular-nums`, проп `align="right"`. |
| `SelectField.vue` | `padding:7px 10px; background:var(--sheet); border:1px solid var(--rule-strong); border-radius:2px; font-size:13px`. |
| `StatusTag.vue` | текст + `border-bottom:2px solid <color>; padding-bottom:2px; font-family:var(--f-data); font-size:9.5px; letter-spacing:.13em; white-space:nowrap`. |
| `SectionRule.vue` | Подзаголовок секции формы: `var(--f-data)` 9.5px `.18em` uppercase `--brass-dark`, `padding-bottom:7px`, `border-bottom:1px solid var(--rule-strong)`. |
| `CheckBox.vue` | Квадрат 15×15 (в списках 16×16), `border:1px solid var(--rule-strong)`, включён → `background:var(--ink)`, метка `✓` цветом `--ink-inv`, 10–11px. Без скруглений. |
| `SegmentedTabs.vue` | Ряд кнопок в общей рамке `1px solid var(--rule-strong)`, активная → `background:var(--ink); color:var(--ink-inv)`, неактивная — прозрачная. |
| `Modal.vue` | `position:fixed; inset:0; background:rgba(27,21,18,.42); display:flex; align-items:center; justify-content:center; padding:28px`. Карточка: `background:var(--sheet); border:1px solid var(--rule-strong); box-shadow:0 26px 60px rgba(27,21,18,.34)`. Слоты `header` (тёмная шапка `--ink`), `default`, `footer`. |
| `SideDrawer.vue` | Ширина 400px, `border-left:1px solid var(--rule-strong)`, `box-shadow:-14px 0 34px rgba(27,21,18,.13)`, `animation:slidein .16s ease-out`. |
| `DataTable.vue` | Обёртка: `flex:1; min-height:0; overflow:auto; background:var(--sheet)`. Шапка — `position:sticky; top:0; background:var(--sheet-hi); border-bottom:1px solid var(--rule-strong)`, ячейки `var(--f-data)` 9.5px `.14em` uppercase `--ink-2`, `padding:10px 12px`. Строки — `border-bottom:1px solid var(--rule-soft)`. Grid-шаблон задаётся снаружи пропом. |
| `MoneyDelta.vue` | `было → стало`: старое значение `--ink-3` + `line-through`, стрелка `→` 9px `--brass`, новое — 12.5px `600`. |

---

## 6. Экран «Вход» — `GET /login`, `Pages/Login.vue`

Корень: `min-height:100vh; display:grid; grid-template-columns:minmax(420px,1fr) minmax(520px,720px); font-size:14px`.

**Левая колонка** — `background:var(--ink); color:var(--ink-inv); padding:56px 56px 40px; display:flex; flex-direction:column; justify-content:space-between`.
- Верх: логотип `height:38px; filter:invert(1)` + рядом по базовой линии `v1.0` — `var(--f-data)` 10px `.18em`, цвет `--brass`, `user-select:none`. **Три клика по «v1.0» открывают служебную консоль** (`/su`).
- Середина (`max-width:34ch`): линия `height:1px; background:var(--brass); margin-bottom:22px`, затем заголовок `var(--f-display)` 27px `line-height:1.3`, ниже подзаголовок 13px `line-height:1.6` цвет `#A99A88`.
- Низ: ряд `var(--f-data)` 10.5px `.13em` цвет `#7A6A5B`, `gap:26px` — `4 ТОЧКИ` (только если модуль points включён), `5 000 SKU`, `TMT`.

**Правая колонка** — `background:var(--sheet); padding:28px 40px 32px; display:flex; flex-direction:column`.
- Сверху справа переключатель языка: две кнопки в рамке `1px solid var(--rule-strong)`, `padding:6px 13px`, `var(--f-data)` 11px `.12em`; активная — `--ink`/`--ink-inv`.
- По центру карточка: `max-width:400px; padding:34px 34px 30px; border:1px solid var(--rule); outline:1px solid var(--rule-soft); outline-offset:5px; background:var(--sheet)`. Двойная рамка через `outline` обязательна.
  - Надзаголовок `var(--f-data)` 10px `.2em` uppercase `--brass-dark`.
  - Заголовок `var(--f-display)` 25px, `margin:8px 0 26px`.
  - Блок ошибки (когда есть): `display:flex; gap:11px; padding:12px 13px; margin-bottom:20px; background:var(--danger-tint); border-left:3px solid var(--danger)`; слева `!` в `var(--f-data)` 11px цветом `--danger`; заголовок 12.5px `600` `--danger`; текст 12px `--ink-2`.
  - Поля «Логин» и «Пароль» (у пароля `letter-spacing:.14em`), кнопка «Войти» — solid, `width:100%; padding:12px 18px; font-weight:600; letter-spacing:.05em`.
  - Внизу карточки через `border-top:1px solid var(--rule-soft)`: 11.5px `--ink-3` — текст «регистрации нет».
- Внизу колонки демо-панель `var(--f-data)` 10px `.1em`: подпись `ДЕМО:` и кнопки-призраки `УСПЕХ`, `ОШИБКА ВХОДА`, `ЗАБЛОКИРОВАН`, а также `СЛУЖЕБНЫЙ ВХОД` — с `border:1px dashed var(--rule-strong)`, hover → `--danger`.

**Тексты (RU / TM), переключаются кнопками:**

| ключ | RU | TM |
|---|---|---|
| heroTitle | Каталог, остатки и права — в одном месте, за прилавком. | Katalog, galyndylar we hukuklar — bir ýerde, satuw nokadynyň arkasynda. |
| heroSub | Панель для администратора магазина. Продавцы работают в мобильном приложении со сканером — сюда они не заходят. | Dükan administratory üçin dolandyryş paneli. Satyjylar skaner bilen ykjam goşundyda işleýärler — bu ýere girmeýärler. |
| panel | Панель управления | Dolandyryş paneli |
| signin | Вход в систему | Ulgama girmek |
| login / password / enter | Логин / Пароль / Войти | Ulanyjy ady / Parol / Girmek |
| noreg | Самостоятельной регистрации нет. Учётную запись выдаёт администратор сети. | Özbaşdak hasaba durmak ýok. Hasaby ulgamyň administratory berýär. |
| errTitle | Неверный логин или пароль | Ulanyjy ady ýa-da parol nädogry |
| errText | Проверьте раскладку клавиатуры. После пяти неудачных попыток вход блокируется на 15 минут. | Klawiatura düzülişini barlaň. Bäş şowsuz synanyşykdan soň giriş 15 minutlyk petiklenýär. |
| blkTitle | Учётная запись заблокирована | Hasap petiklendi |
| blkText | Доступ закрыт администратором 24.07.2026. Обратитесь к администратору сети. | Girişi administrator 24.07.2026-da ýapdy. Ulgamyň administratoryna ýüz tutuň. |

Поведение: логин по умолчанию `aynur`. Логин `root` (или «Служебный вход») ведёт на `/su`, любой другой — на `/products`. Enter в поле пароля отправляет форму.

---

## 7. Каркас панели — `Layouts/AdminLayout.vue`

Корень: `height:100vh; overflow:hidden; display:flex; flex-direction:column; font-size:14px`.

**(опционально) Полоса «просмотр глазами администратора»** — показывается, если в сессию вошёл суперадмин: `background:#2A1613; color:#E6BFA6; padding:6px 20px; border-bottom:1px solid var(--danger)`. Слева `var(--f-data)` 10px `.15em` uppercase: `Просмотр глазами администратора · модулей включено: {N} из 6`. Справа кнопка `← В служебную консоль` — `border:1px solid var(--danger)`, hover заливается `--danger`.

**Шапка** — `background:var(--ink); color:var(--ink-inv); height:56px; padding:0 20px; display:flex; align-items:center; gap:22px`.
- Логотип `height:20px; filter:invert(1)` + `КАТАЛОГ` (`var(--f-data)` 9.5px `.14em`, `--brass`).
- Поиск: `flex:1; max-width:520px; position:relative`. Инпут `padding:9px 12px 9px 34px; background:#241D18; border:1px solid #3E3229; border-radius:2px; color:var(--ink-inv); font-size:13px`, `placeholder="Штрихкод, артикул или название"`. Слева абсолютом символ `‖|‖` — `var(--f-data)` 11px, цвет `--brass`, `left:12px; top:50%; transform:translateY(-50%)`.
- Распорка `flex:1`.
- Справа: часы `28.07.2026 · 14:12` (`var(--f-data)` 10.5px `.1em`, `#8C7B69`); через `border-left:1px solid #3E3229` — квадрат 26×26 с инициалами `АД` (`border:1px solid var(--brass)`, цвет `--brass`, `var(--f-data)` 10px), имя `Айнур Д.` 12px и роль `Администратор` 10px `#8C7B69`; кнопка `Выход` — `border:1px solid #3E3229`, цвет `#A99A88`, hover → `--brass` / `--ink-inv`.

**Табы разделов** — `background:var(--sheet-hi); border-bottom:1px solid var(--rule-strong); height:40px; padding:0 14px; display:flex; gap:2px; overflow-x:auto`.
Кнопка: `padding:0 15px; font-size:13px; border:0; border-bottom:2px solid transparent; color:var(--ink-3)`. Активная: `background:var(--sheet); border-bottom-color:var(--brass); color:var(--ink); font-weight:600`.

Разделы по порядку (скрываются вместе со своим модулем):
`Товары` (`/products`) · `Импорт` (`/import`, модуль import) · `Пользователи` (`/users`) · `Матрица прав` (`/rights`) · `Точки и склады` (`/points`, модуль points) · `Синхронизация` (`/devices`, модуль devices) · `Журнал действий` (`/audit`, модуль audit).

Скрытый раздел должен быть недоступен и по прямому URL — контроллер возвращает 404.

---

## 8. Товары — `GET /products`, `Pages/Products/Index.vue`

### 8.1 Панель фильтров
`padding:16px 20px 12px; display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; justify-content:space-between`.
Слева селекты с микро-подписями `.16em`: **Точка** (`Все точки` + список, только при модуле points) и **Статус** (`Любой` / `В продаже` / `Скрыт`). Если задан поиск или фильтр — кнопка-призрак `Сбросить фильтры`.
Справа: solid `+ Добавить товар`, ghost `Импорт из Excel` (при модуле import), ghost `Экспорт`. Все `padding:9px 15px; font-size:13px`.

### 8.2 Таблица
Один и тот же grid у шапки и строк:
```
8px 34px minmax(260px,3fr) minmax(190px,1.5fr) minmax(120px,1fr) minmax(78px,.6fr) minmax(120px,1fr) minmax(110px,.85fr)
```
Колонки: цветная полоска категории · чекбокс · **Номенклатура** (сортируемая) · **Артикул · штрихкод** (сортируемая) · **Розн., TMT** (сортируемая, по правому краю) · **Скидка** · **Со скидкой** · **Статус**.
Шапка `sticky top:0`, кнопки сортировки — прозрачные, hover цвет `--brass-dark`, рядом маркер `↑` / `↓` / `·` (`·` = не сортируется по этой колонке; у «Розн.» маркер идёт **перед** текстом).

Строка:
- полоска категории `align-self:stretch` цветом kind;
- имя 13px `500`, под ним `main_code` — `var(--f-data)` 10.5px `--ink-3`;
- артикул `var(--f-data)` 12.5px, под ним штрихкод 10.5px `--ink-3`;
- цена `var(--f-data)` 13.5px; **если есть скидка — цена перечёркнута и цветом `--ink-3`**;
- скидка `{N} %` цветом `--danger`, без скидки — `—` цветом `--ink-3`;
- «со скидкой» 13.5px, при скидке `font-weight:600` и цвет `--danger`;
- статус — `StatusTag`;
- вся строка кликабельна (открывает карточку), выбранная строка и hover — `background:var(--sheet-hi)`.

Индикатор запроса: над строками полоса `ЗАПРОС К СЕРВЕРУ…` — `var(--f-data)` 10.5px `.12em`, `color:var(--brass-dark)`, `background:var(--brass-tint)`, `padding:10px 20px`. Показывается 240 мс при каждом изменении фильтра/сортировки/страницы.

Пустое состояние: по центру `padding:64px 24px` — `var(--f-display)` 21px «Ничего не найдено», текст 13px `--ink-3` с подстановкой запроса, solid-кнопка `Сбросить всё`.

### 8.3 Панель выбранных (когда есть отмеченные)
`background:var(--ink); color:var(--ink-inv); padding:10px 20px; display:flex; justify-content:space-between`.
Слева `Выбрано товаров: N` (`var(--f-data)` 12px, цвет `--brass`) и ссылка-кнопка `снять выделение`.
Справа: `Изменить цену или скидку` (`background:var(--brass-dark); border:1px solid var(--brass)`), `Перевести на точку` (только при модуле productPoints), `Скрыть из продажи` — обе с `border:1px solid #3E3229`.

### 8.4 Подвал
`border-top:1px solid var(--rule-strong); background:var(--sheet-hi); padding:9px 20px`. Слева `Строки 1–25 из 214`, по центру строка запроса в моно 10px с многоточием:
`GET /api/v1/products?q=&point=all&status=all&sort=name&page=1` (при убывающей сортировке перед ключом ставится `-`).
Справа: ghost `← Назад`, `Стр. 1 / 9` (`var(--f-data)` 11.5px), ghost `Вперёд →`. По 25 строк на страницу.

### 8.5 Модалка «Редактирование товара» (660px)
Тёмная шапка: заголовок `var(--f-display)` 20px, подпись `карточка {main_code} · изменения уйдут на устройства при синхронизации` (`var(--f-data)` 10.5px, `#A99A88`), справа кнопка `Отмена`.
Тело (скроллится):
- Поле «Номенклатура».
- Ряд `minmax(130px,1fr) minmax(130px,1fr) minmax(200px,1.5fr)`: «Основной код», «Артикул», «Штрихкод EAN-13» — все моно.
- Подсказка под рядом 11.5px `--ink-3`: по умолчанию «Основной код — ключ сопоставления при импорте прайса. Меняйте, только если он изменился у поставщика.»; при изменении кода — «Основной код меняется с {старый} на {новый}. Старый код останется в истории импорта и в чеках.»
- Секция **Цена** (`SectionRule`), ряд `minmax(160px,1fr) 110px minmax(180px,1.2fr)`: «Розничная, TMT» (моно 15px, по правому краю), «Скидка, %» и плашка результата `padding:9px 12px; background:var(--sheet-alt); border-left:3px solid var(--brass)` с подписью «Со скидкой» и значением `var(--f-data)` 17px.
- Если цену/скидку трогали: строка 11.5px цветом `--danger` — «Ручная правка перезапишется при следующем импорте прайса, если в файле по артикулу {sku} будет другая цена.»
- Секция **Остаток по точкам** (при модуле productPoints): строки «название/адрес · код точки · инпут 72px справа», внизу «Итого — {N} шт» (`var(--f-data)` 15px `600`).
  При выключенном модуле — секция **Остаток**: одно поле 140px «Количество, шт» и пояснение «Общий остаток по сети. Разбивка по точкам выключена — старые значения сохранены в базе и вернутся, если модуль снова включат.»
- Секция **История цены**: строки «дата · кто · было (перечёркнуто) · → · стало», рост зелёным `--ok`, падение `--danger`.
Подвал модалки: `background:var(--sheet-hi); border-top:1px solid var(--rule-strong)`. Слева селект «Статус» и подсказка, справа ghost `Отмена` + solid `Сохранить изменения`.
Подсказки статуса: active → «Товар выдаётся устройствам при синхронизации и доступен для продажи.», hidden → «Карточка остаётся в базе и в отчётах, но на кассу и в приложение не попадает.», archived → «Архивный товар нельзя пробить; остаток фиксируется на текущем значении.»

### 8.6 Модалка «Новый товар» (660px)
Тёмная шапка: `Новый товар`, подпись `основной код присвоится автоматически · AA13xx`.
Поля: «Номенклатура»; ряд `minmax(140px,1fr) minmax(220px,1.6fr)` — «Артикул» и «Штрихкод EAN-13» с кнопкой-призраком `Сгенерировать`; секция «Цена» тем же рядом, что в 8.5.
Блок ошибок вверху тела: `background:var(--danger-tint); border-left:3px solid var(--danger); padding:12px 14px`, список строк 12.5px.
Правила валидации (тексты дословно):
- «Не заполнена номенклатура — без названия продавец не поймёт, что за товар.»
- «Артикул должен быть числом из 4 и более цифр — по нему сопоставляется прайс при импорте.» / «Артикул {sku} уже есть в каталоге.»
- «Штрихкод должен быть EAN-13 — ровно 13 цифр, иначе сканер в зале не найдёт товар.» / «Штрихкод {barcode} уже занят другим товаром.»
- «Розничная цена должна быть больше нуля.»
- «Скидка допустима от 0 до 90 %.»
Подсказка под статусом: со скидкой — «Продавец увидит {итог} TMT: розница {цена} минус {N} %.», без — «Продавец увидит розничную цену без скидки.»
Кнопка сохранения: `Сохранить и отправить в CMS`. После сохранения — тост над таблицей: `background:rgba(78,106,70,.12); border-left:3px solid var(--ok)`, текст «{НАЗВАНИЕ} добавлен. Устройства получат карточку при ближайшей синхронизации.» и ссылка `скрыть`.

### 8.7 Модалка «Изменить цену или скидку» (560px)
Шапка: надзаголовок `Выбрано товаров: N` (`var(--f-data)` 9.5px `.18em` `--brass-dark`), заголовок `var(--f-display)` 22px.
Режимы (`SegmentedTabs`, растягиваются на всю ширину): `Скидка, %` · `Цена ± %` · `Цена ± TMT` · `Цена = `.
Подпись поля по режиму:
- discount → «Единая скидка для всех выбранных, %»
- percent → «Изменить розничную цену на, % (минус — со знаком −)»
- amount → «Изменить розничную цену на, TMT»
- fixed → «Новая розничная цена, TMT»
Пояснения по режиму:
- discount → «Розничная цена не меняется — переписывается только колонка «Скидки», цена со скидкой пересчитается сама.»
- percent → «Меняется розничная цена. Действующие скидки остаются в процентах, поэтому цена со скидкой сдвинется следом.»
- amount → «Прибавка в манатах к текущей цене каждого товара, копейки округляются до сотых.»
- fixed → «Одинаковая цена для всех выбранных товаров — обычно нужно для распродажных наборов.»
Блок предпросмотра «Как это ляжет на первые строки» — первые 3 выбранных товара, `было → стало`; рост зелёным, снижение красным. Кнопка: `Применить к N`.

---

## 9. Импорт — `GET /import`, `Pages/Import/Index.vue`

Трёхшаговый мастер. Полоса шагов: `background:var(--sheet-hi); border-bottom:1px solid var(--rule); padding:0 20px`. Шаг: `padding:11px 18px 11px 14px; gap:9px; font-size:13px; border-bottom:2px solid transparent`; активный — `--brass` + `font-weight:600`; пройденный — кликабельный, номер заменяется на `✓`. Номер — квадрат 20×20 `var(--f-data)` 10.5px: активный `--ink`/`--ink-inv`, пройденный `--ok`/`--ink-inv`, будущий — прозрачный с `border:1px solid var(--rule-strong)`.
Шаги: `1 Файл` · `2 Колонки` · `3 Проверка строк`.

### Шаг 1 — Файл
Контейнер `max-width:860px; margin:0 auto; padding:32px 20px`, фон `--paper`.
Заголовок `var(--f-display)` 26px «Загрузка файла каталога»; лид 13px `--ink-3` `max-width:64ch`: «Прайс — единственный источник цен: панель ничего не придумывает сверх семи колонок файла. Ключ сопоставления — артикул. Строки, которых нет в файле, останутся в каталоге без изменений.»
Дропзона: `padding:44px 28px; border:2px dashed var(--rule-strong); background:var(--sheet); text-align:center`, hover → `border-color:var(--brass); background:var(--sheet-hi)`. Внутри: `ПЕРЕТАЩИТЕ ФАЙЛ СЮДА` (`var(--f-data)` 11px `.18em` `--brass-dark`), `var(--f-display)` 20px «или выберите на компьютере», 12px `--ink-3` «.xlsx · колонки: основной код, артикул, штрихкод, номенклатура, розничная цена, скидки, цена со скидкой», и «кнопка» `Выбрать файл`.
Ниже две карточки `repeat(auto-fit,minmax(240px,1fr))`, `border:1px solid var(--rule); background:var(--sheet); padding:16px`:
- **Шаблон** — «Скачайте шаблон с готовыми колонками и списком кодов точек — так сопоставление пройдёт автоматически.» + ghost `Скачать шаблон .xlsx`.
- **Последние импорты** — подпись «строк загружено / с ошибками» и строки: `24.07.2026 · Price list 24.07.xlsx · 1 204 / 0` (зелёным), `17.07.2026 · Price list 17.07.xlsx · 860 / 12` (`--warn`), `11.07.2026 · Price list_full.xlsx · 1 891 / 3` (`--warn`).

### Шаг 2 — Колонки
Заголовок «Сопоставьте колонки файла», лид «Слева — что нашлось в файле, справа — поле каталога. Совпадения подобраны автоматически, проверьте спорные.»
Grid `minmax(56px,.5fr) minmax(170px,1.2fr) minmax(200px,1.6fr) minmax(220px,1.4fr) minmax(150px,1fr)`, шапка: `Кол.` · `Заголовок в файле` · `Пример значения` · `Поле каталога` · `Совпадение` (по правому краю).
Строки:
```
A · Основной код     · AA1001                          · mainCode · точное
B · Артикул          · 510028                          · sku      · точное
C · Штрихкод         · 8011003993802                   · barcode  · точное
D · Номенклатура     · VERSACE BRIGHT CRYSTAL EDT 30ML · name     · точное
E · Розничная цена   · 1 415,88                        · retail   · точное
F · Скидки           · 0,5                             · discount · точное
G · Цена со скидкой  · 757,62 (=E−E×F)                 · final    · формула
```
Варианты в селекте: `— не импортировать —`, `Основной код`, `Артикул`, `Штрихкод`, `Номенклатура`, `Розничная цена`, `Скидки`, `Цена со скидкой`.
Цвет пометки: `точное` → `--ok`, `вероятно` → `--warn`, прочее (`формула`, `пропущена`) → `--ink-3`. Строка с `skip` — фон `--sheet-alt`; с «вероятно» — `rgba(176,122,22,.07)`.
Подвал: слева «Сопоставлено колонок: {N} из 7. Ключ сопоставления — артикул. «Цена со скидкой» в файле формула E−E×F: если пусто, пересчитаем сами.», справа ghost `Назад` + solid `Проверить строки`.

### Шаг 3 — Проверка строк
Шапка (`background:var(--sheet-hi)`): заголовок «Импорт каталога из Excel», под ним `Price list 28.07.xlsx`, серым «лист «Лист1» · колонки A–G · шапка в строке 3 · данные с 4-й» и ghost-кнопка `Другой файл`.
Справа — счётчики в общей рамке `1px solid var(--rule-strong)`, четыре ячейки `padding:9px 16px` с разделителями: `Всего строк 1 894` · `Готовы` (`--ok`) · `Предупреждения` (`--warn`) · `Ошибки` (`--danger`). Значения — `var(--f-data)` 19px по правому краю. «Готовы» = 1894 − предупреждения − ошибки.
Полоса фильтра: `SegmentedTabs` `Все строки` / `Только проблемные`, справа 12px `--ink-3`: «Пустая «Цена со скидкой» — скидки нет, применим розничную. Правьте строки здесь: перезаливать файл не нужно, строки с ошибками не импортируются.»
Grid строк:
```
52px minmax(84px,.7fr) minmax(90px,.8fr) minmax(126px,1fr) minmax(230px,2.2fr) minmax(104px,.9fr) 68px minmax(104px,.9fr) minmax(240px,2.2fr)
```
Колонки: `Стр.` · `Осн. код` · `Артикул` · `Штрихкод` · `Номенклатура` · `Розн. цена` · `Скидка` · `Со скидкой` · `Что не так`.
Раскраска строки по типу: ok — прозрачно; warn — `rgba(176,122,22,.09)` + левая полоса `3px solid var(--warn)`; err — `var(--danger-tint)` + полоса `--danger`; исправленная — `rgba(78,106,70,.10)` + полоса `--ok`.
Тег проблемы — `var(--f-data)` 9.5px `.12em` в рамке `1px solid <цвет>`, `padding:2px 6px`. У исправимых ошибок в той же ячейке — инпут 118px с плейсхолдером-подсказкой и маленькая solid-кнопка `Исправить`; после исправления тег меняется на `ИСПРАВЛЕНО` и текст на «Строка исправлена вручную и войдёт в импорт.».
Пустая «Со скидкой» отображается как `= розн.`, пустой код/артикул — как `—`.

Строки-образцы (воспроизвести дословно):

| Стр | Осн. код | Артикул | Штрихкод | Номенклатура | Цена | Скидка | Со скидкой | Тип | Тег | Сообщение | fix |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 4 | AA1001 | 510028 | 8011003993802 | VERSACE BRIGHT CRYSTAL EDT 30ML | 1 415,88 | | | ok | | | |
| 5 | AA1002 | 510030 | 8011003993819 | VERSACE BRIGHT CRYSTAL EDT 50ML | 1 920,96 | | | ok | | | |
| 6 | AA1003 | 510032 | 8011003993826 | VERSACE BRIGHT CRYSTAL EDT 90ML | 2 426,04 | | | ok | | | |
| 7 | AA1004 | 510040 | 8011003993833 | VERSACE BRIGHT CRYSTAL DEODORANT 50ML | 732,78 | | | ok | | | |
| 8 | AA1005 | 510023 | 8011003817719 | VERSACE BRIGHT CRYSTAL DEOSTICK 50ML | 507,38 | | | ok | | | |
| 9 | AA1006 | 510048 | 8011003993840 | VERSACE BRIGHT CRYSTAL BATH&SHOWER GEL 200ML | 644,00 | | | ok | | | |
| 10 | AA1007 | 510050 | 8011003993857 | VERSACE BRIGHT CRYSTAL BODY LOTION 200ML | 788,90 | | | ok | | | |
| 11 | AA1008 | 511028 | 8011003819423 | VERSACE BRIGHT CRYSTAL ABSOLU EDP 30ML | 1 515,24 | 0,5 | 757,62 | ok | | | |
| 12 | AA1009 | 511030 | 8011003818174 | VERSACE BRIGHT CRYSTAL ABSOLU EDP 50ML | 2 119,68 | | | warn | ОКРУГЛЕНИЕ | В файле 2 119,679999… — округлим до 2 знаков при импорте. | |
| 19 | AA1016 | 510092 | 801100399391 | VERSACE EROS EDT 50ML | 1 780,00 | | | err | ШТРИХКОД | В штрихкоде 12 цифр вместо 13. Проверьте, не потерялась ли последняя цифра. | 13 цифр |
| 27 | AA1024 | 511044 | 8011003818501 | ARMANI ACQUA DI GIO EDT 100ML | 2 340,00 | 120 | 2 220 | err | СКИДКА | Скидка задана суммой. В колонке «Скидки» ожидается доля: 0,2 или 20 %. | 0,2 |
| 33 | AA1030 | *(пусто)* | 8011003818600 | ARMANI SI PASSIONE EDP 50ML | 1 890,00 | | | err | АРТИКУЛ | Пустой артикул. Строка не сопоставляется с каталогом — артикул это ключ. | 511xxx |
| 41 | AA1002 | 510030 | 8011003993819 | VERSACE BRIGHT CRYSTAL EDT 50ML | 1 899,00 | | | err | ДУБЛЬ | Артикул 510030 уже встречался в строке 5 с другой ценой. Какая верная? | цена |
| 46 | AA1039 | 511702 | 8011003818730 | D&G THE ONE EDP 75ML | 1 200,00 | 0,3 | 900,00 | warn | РАСХОЖДЕНИЕ | Цена со скидкой не сходится с формулой E−E×F: должно быть 840,00. Возьмём значение из файла. | |
| 58 | AA1047 | 512010 | 8011003818877 | D&G LIGHT BLUE EDT 100ML | 2 210 манат | | | err | ЦЕНА | В цене текст. Уберите «манат» — колонка числовая, валюта всегда TMT. | 2210 |
| 63 | *(пусто)* | 512044 | 8011003819001 | HUGO BOSS BOTTLED EDT 50ML | 1 640,00 | | | warn | НОВЫЙ | Основной код пуст — товар будет создан, код присвоится автоматически. | |
| 71 | AA1055 | 512080 | 8011003819100 | *(пусто)* | 980,00 | | | err | НОМЕНКЛАТУРА | Пустая номенклатура. Название обязательно — продавец ищет товар по нему. | название |

Подвал: слева итог — при наличии ошибок «Строк с ошибками: {N}. Они будут пропущены, остальные {ok} импортируются.», без ошибок «Ошибок не осталось. Будут импортированы все {ok} строк, {warn} — с предупреждением.». Справа ghost `Выгрузить отчёт об ошибках` + solid `Импортировать {ok} строк` / `Импортировать {ok} строк, {err} пропустить`.

---

## 10. Пользователи — `GET /users`, `Pages/Users/Index.vue`

Шапка раздела: `var(--f-display)` 23px «Сотрудники и доступ», лид 12.5px `--ink-3` `max-width:60ch`: «Учётные записи выдаёте вы: самостоятельной регистрации в приложении нет. Роли две — администратор работает в этой панели, продавец только заходит в мобильное приложение и видит данные из панели по API.» Справа solid `+ Новый сотрудник`.

Grid (колонка «Точки» появляется только при модуле points):
```
minmax(200px,1.6fr) minmax(150px,1.1fr) [minmax(150px,1.2fr)] minmax(170px,1.2fr) minmax(230px,1.4fr)
```
Колонки: `Сотрудник` · `Роль` · `Точки` · `Последний вход` · `Доступ` (справа).
Ячейка сотрудника: имя 13.5px `500`, логин `var(--f-data)` 11px `--ink-3`, при недавней смене пароля — строка `ПАРОЛЬ СМЕНЁН СЕГОДНЯ` (`var(--f-data)` 9.5px `.12em` uppercase `--brass-dark`).
Точки — чипы `var(--f-data)` 9.5px в рамке `1px solid var(--rule-strong)`, `padding:2px 5px`.
Последний вход — моно 12px + модель устройства 11px `--ink-3`.
Действия: ghost `Пароль` и переключатель доступа — активен: рамка и текст `--ok`, прозрачный фон, подпись `активен`; заблокирован: `background:var(--danger); color:var(--ink-inv)`, подпись `заблокирован`, а вся строка красится в `--danger-tint`.

### Панель «Новый сотрудник» (SideDrawer 400px)
Тёмная шапка `padding:14px 18px` с `var(--f-display)` 18px и кнопкой `Закрыть`.
Поля: «Имя и фамилия» (`placeholder="Огулджан Мурадова"`), «Логин» (моно, `placeholder="oguljan"`), «Роль» (`Продавец` / `Администратор`) + пояснение «Продавец — только вход в мобильное приложение. Администратор — вся эта панель: каталог, цены, импорт, сотрудники.»
«Точки продаж» (при модуле points) — список-кнопок в рамке `1px solid var(--rule); background:var(--sheet-alt)`: чекбокс 16×16, название 13px, адрес 11px `--ink-3`, справа код точки моно 9.5px. Выбранная строка — `background:var(--sheet-hi)`. Под списком: «Выбрано точек: {N}. Остатки и цены сотрудник увидит только по ним.» либо «Пока не выбрано ни одной точки — сотрудник не увидит остатки нигде.»
«Пароль» — `SegmentedTabs` `Сгенерировать` / `Задать вручную`.
- Генерация: блок `border:1px solid var(--rule); background:var(--sheet-alt); padding:14px`, надпись `ПАРОЛЬ ДЛЯ ВЫДАЧИ`, сам пароль `var(--f-data)` **24px** `letter-spacing:.1em`, кнопка `Другой пароль`, сноска «Покажется один раз при создании — передайте его сотруднику лично.»
- Вручную: поле `type="text"` (`placeholder="минимум 8 символов"`) и список правил, каждое — `✓` (цвет `--ok`) или `·` (`--ink-3`): «не короче 8 символов», «есть латинская буква и цифра», «без пробелов».
Подвал: ghost `Отмена` + solid `Создать и выдать доступ`.

**Генератор пароля:** слоги `ba ru me ko sa ni tu le da vi no ze`, формат `слог+слог-слог+слог-NNN` (3 цифры 100–999). Пример: `sani-tule-482`.

### Модалка блокировки (520px)
Надзаголовок `БЛОКИРОВКА ДОСТУПА` цветом `--danger`. Заголовок «Заблокировать {Имя}?» / «Вернуть доступ {Имя}?».
Текст при блокировке: «Сессия в мобильном приложении будет завершена немедленно — устройство {устройство} выкинет на экран входа, даже если приложение открыто прямо сейчас.» При разблокировке: «Сотрудник снова сможет войти в мобильное приложение под логином {login}. Пароль остался прежним.»
Блок `ЧТО ПРОИЗОЙДЁТ СРАЗУ` (`border:1px solid var(--rule); background:var(--sheet-alt)`, `white-space:pre-line`):
```
· токен доступа отзывается, офлайн-кэш каталога на устройстве стирается
· незакрытые продажи с устройства не отправятся
· запись о блокировке уйдёт в журнал действий
```
(для разблокировки — «· выдаётся новый токен, каталог скачается при первом входе» и «· запись о разблокировке уйдёт в журнал действий»).
Кнопка: `Заблокировать и завершить сессию` (фон `--danger`) / `Вернуть доступ` (фон `--ink`).

### Модалка смены пароля (560px), два состояния
**Редактирование:** надзаголовок `СМЕНА ПАРОЛЯ`, заголовок «Новый пароль для {Имя}», лид «Логин {login} не меняется. Старый пароль перестанет работать сразу после сохранения.»
Табы `Сгенерировать` / `Задать вручную` — как в панели создания, плюс кнопка `Скопировать` (после клика надпись меняется на `Скопировано`, используется `navigator.clipboard`).
Блок `ПОСЛЕ СОХРАНЕНИЯ` — два переключателя-строки с чекбоксом 16×16:
- «Потребовать смену при первом входе» / «Сотрудник задаст свой пароль сам сразу после входа.»
- «Завершить активные сессии» / «Устройство {устройство} выйдет на экран входа.»
Кнопка `Сменить пароль` неактивна (фон `--rule-strong`, `cursor:not-allowed`), пока ручной пароль не проходит все три правила.
**Готово:** надзаголовок `ПАРОЛЬ ИЗМЕНЁН` цветом `--ok`, заголовок «Пароль для {login} обновлён», текст «Покажите его сотруднику один раз — после закрытия окна пароль больше нигде не отображается.», пароль на плашке `background:var(--brass-tint); border-left:3px solid var(--brass)` шрифтом 24px, блок `ЧТО ПРОИЗОШЛО` со строками по выбранным опциям + «· запись о смене пароля ушла в журнал действий». Кнопка `Готово`.

---

## 11. Матрица прав — `GET /rights`, `Pages/Rights/Index.vue`

Двухколоночный экран: слева матрица, справа фиксированная колонка 340px `border-left:1px solid var(--rule-strong); background:var(--sheet)`.

Шапка: `var(--f-display)` 23px «Кто какие поля карточки видит», лид `max-width:62ch`: «В системе две роли. Администратор ведёт данные в этой панели, продавец только заходит в мобильное приложение и получает их по API. Настраивается одно: какие поля карточки уходят продавцу. Скрытое поле не приходит в приложение вообще, а не прячется на экране.» Справа `РОЛЕЙ В СИСТЕМЕ: 2`.

Матрица: `display:inline-grid; grid-template-columns:minmax(196px,1.3fr) repeat(7,minmax(98px,1fr)); border:1px solid var(--rule-strong); background:var(--rule-soft); gap:1px; min-width:100%`. Фон контейнера + `gap:1px` рисуют сетку — отдельных бордеров у ячеек нет.

Поля (7 колонок, с примерами для предпросмотра):
```
mainCode Основной код               AA1001
name     Номенклатура               VERSACE BRIGHT CRYSTAL EDT 30ML
sku      Артикул                    510028
barcode  Штрихкод                   8011003993802
stock    Остаток                    БРК 12 · ГЛС 4 · М30 0
retail   Розничная цена             1 415,88 TMT
discount Скидка и цена со скидкой   50 % · 757,62 TMT
```
Шапка колонки: название 12px + кнопка-призрак `скрыть всем` / `открыть всем` (`var(--f-data)` 9px).
Роли: `Администратор` — «Веб-панель: каталог, цены, импорт, доступы. Менять нельзя», строка `opacity:.62`, все ячейки `всегда` на фоне `--sheet-alt`, клики не работают. `Продавец` — «Только мобильное приложение — данные из этой панели по API», по умолчанию скрыт только `Основной код`.
Ячейка (`min-height:56px; padding:16px 8px; var(--f-data) 10.5px .1em`): включено → фон `--ink`, текст `--ink-inv`, подпись `видно`; выключено → фон `--sheet`, текст `--ink-3`, подпись `скрыто`; изменено и не сохранено → фон `--brass-tint`, текст `--brass-dark`, `box-shadow:inset 0 0 0 1px var(--brass)`.
В заголовке строки роли — счётчик `{N} / 7 полей`.
Легенда под матрицей: три квадрата 14×14 — `видно` (`--ink`), `скрыто — поле не уходит в API` (`--sheet` в рамке), `изменено, не сохранено` (`--brass-tint` в рамке `--brass`).
Подвал: слева «Есть несохранённые изменения. После сохранения приложения продавцов получат новую политику при следующей синхронизации.» либо «Политика сохранена 24.07.2026, Айнур Д.»; справа ghost `Вернуть как было` + solid `Сохранить политику`.

Правая колонка «Проверка»: надзаголовок `ПРОВЕРКА`, `var(--f-display)` 18px «Карточка глазами роли», селект ролей. Ниже плашка `border:1px solid var(--rule); background:var(--sheet-alt); padding:14px` с подписью `ЭКРАН СКАНЕРА · МОБИЛЬНОЕ ПРИЛОЖЕНИЕ` и списком полей: подпись `var(--f-data)` 9px `.13em`, значение — либо пример моно 12.5px, либо курсивом 12px `--ink-3` «поле скрыто для этой роли» и вся строка `opacity:.5`.
Сноска: «Роль «{Роль}» видит карточку целиком.» либо «Скрыто полей: {N}. Скрытые поля не приходят в /api/v1/products — их нельзя увидеть даже через перехват трафика.»

---

## 12. Остальные разделы

### Точки и склады — `GET /points`
Заголовок «Точки продаж и склады», лид «Остаток товара хранится по каждой точке отдельно. Склад участвует в остатках, но не в продажах.», справа solid `+ Добавить точку`.
Grid: `64px minmax(180px,1.4fr) minmax(220px,1.8fr) minmax(120px,1fr) minmax(120px,1fr) minmax(130px,1fr)`; колонки `Код` · `Название` · `Адрес` · `SKU в наличии` · `Сотрудников` · `Статус`. Код — моно 11.5px `.1em` цветом `--brass-dark`. Статус: `АКТИВНА` (`--ok`) / `СКЛАД` (`--ink-3`).

### Синхронизация — `GET /devices`
Заголовок «Синхронизация устройств», лид `max-width:58ch`: «Если устройство давно не выходило на связь, продавец торгует по старым ценам. Версия данных — номер последней применённой ревизии каталога.» Справа плашка `border:1px solid var(--rule-strong); background:var(--sheet); padding:9px 16px` — `ВЕРСИЯ КАТАЛОГА НА СЕРВЕРЕ` и `4193` (`var(--f-data)` 19px).
Grid: `8px minmax(190px,1.4fr) [minmax(150px,1.1fr)] minmax(160px,1.2fr) minmax(180px,1.3fr) minmax(150px,1.1fr) minmax(120px,1fr)`; первая колонка — вертикальная полоса цвета уровня (`align-self:stretch`). Колонки: `Сотрудник` · `Точка` · `Устройство` · `Последняя связь` · `Версия данных` · `Отставание`. Строки уровня bad/blocked — фон `--danger-tint`.

### Журнал действий — `GET /audit`
Заголовок «Журнал действий», лид «Записи не удаляются и не правятся. Хранение — 24 месяца.» Справа селект `Тип события`: `Все события` / `Цены` / `Права` / `Сотрудники` / `Товары`.
Grid без шапки: `minmax(150px,.9fr) minmax(130px,.8fr) minmax(180px,1.1fr) minmax(220px,1.6fr) minmax(200px,1.2fr)`.
Колонки: время (моно 12px `--ink-3`) · кто · тип события (`var(--f-data)` 10px `.1em` uppercase + `border-bottom:2px solid` цветом типа) · объект · дельта `было → стало` по правому краю.

### Заглушка нереализованного раздела
По центру: `var(--f-display)` 26px с названием раздела, текст «Этот раздел — следующий шаг. Сначала утверждаем вход и список товаров, потом рисую импорт, пользователей, матрицу прав, точки, устройства и журнал в этом же направлении.» и solid `Вернуться к товарам`.

---

## 13. Служебная консоль — `GET /su`, `Pages/Su/Index.vue`

Отдельный layout. Вход: логин `root` либо тройной клик по «v1.0» на экране входа, либо кнопка `СЛУЖЕБНЫЙ ВХОД`. Ссылок на неё из панели администратора нет.

Шапка 56px: `background:#150E0C; border-bottom:2px solid var(--danger)`. Логотип + `СЛУЖЕБНАЯ КОНСОЛЬ` (`var(--f-data)` 9.5px `.16em`, `#D08C7E`). Справа часы, квадрат 26×26 `SU` в рамке `--danger` цветом `#D08C7E`, `root` (моно 12px) и `Суперадмин · роль скрыта` 10px, кнопка `Выход` (hover → `--danger`).

Тело: `display:grid; grid-template-columns:minmax(0,1fr) 400px`.

**Левая колонка** (`padding:20px 22px 28px`): заголовок `var(--f-display)` 26px «Модули системы», лид `max-width:74ch`: «Выключенный модуль пропадает из панели администратора целиком: разделы, фильтры, колонки и поля форм. Данные при этом остаются в базе — включите модуль обратно, и всё вернётся на свои места вместе с накопленной историей.»
Карточка модуля (`border:1px solid var(--rule); padding:16px 18px`, фон `--sheet` если включён, иначе `--sheet-alt`; недоступный — `opacity:.72`):
- название `var(--f-display)` 19px + тег `ВКЛЮЧЁН` (`--ok`) / `ВЫКЛЮЧЕН` (`--danger`) / `НЕДОСТУПЕН` (`--ink-3`) — тем же приёмом `border-bottom:2px`;
- описание 12.5px `--ink-3` `max-width:62ch`;
- справа кнопка `Выключить` (ghost с рамкой `--danger`, текст `--danger`) или `Включить` (solid);
- ряд чипов «что затрагивает» — `var(--f-data)` 10px в рамке; у выключенного модуля чипы перечёркнуты и приглушены;
- нижняя строка через `border-top:1px solid var(--rule-soft)`: `В базе сохранено: {kept}` и, если заблокирован зависимостью, справа красным «Неактивен, пока выключен модуль «{Родитель}».»
Внизу блок `ДОСТУП К КОНСОЛИ`: «Роль «Суперадмин» не отображается в списке сотрудников, в матрице прав и в журнале выдачи доступов. Ссылки на консоль в панели администратора нет — вход только по логину **root** с отдельным ключом. Действия суперадмина пишутся в отдельный служебный журнал.»

**Правая колонка** (`border-left:1px solid var(--rule-strong); background:var(--sheet)`), три блока через `border-bottom:1px solid var(--rule)`:
1. `ПАНЕЛЬ АДМИНИСТРАТОРА СЕЙЧАС` — список всех 7 разделов: название (скрытый — перечёркнут и `--ink-3`) и метка `виден` (`--ok`) / `скрыт` (`--danger`) моно 9.5px uppercase. Подпись: «Администратор видит все разделы панели.» либо «Скрыто разделов: {N} из 7. Скрытые разделы недоступны и по прямой ссылке, API их тоже не отдаёт.» Кнопка на всю ширину solid `Открыть панель администратора`.
2. `ДАННЫЕ ВЫКЛЮЧЕННЫХ МОДУЛЕЙ` — `Точки и склады — 4 записи`, `Остатки по точкам — 12 480 строк`, `Привязки сотрудников — 9 связей`, `История перемещений — 1 204 документа`. Сноска: «Ничего не удаляется и не архивируется: записи просто перестают отдаваться в API и в интерфейс.»
3. `СЛУЖЕБНЫЙ ЖУРНАЛ` — записи «время / текст». Стартовая: `28.07.2026 · 09:41` — «Модули «Точки продаж», «Склады» и «Остатки товара по точкам» выключены по заявке заказчика. Данные сохранены.» Каждое переключение модуля добавляет запись сверху с временем `28.07.2026 · 14:{13,14,15…}`: «Модуль «{Название}» включён — разделы и поля вернулись в панель администратора» либо «Модуль «{Название}» выключен — администратор больше не видит эти разделы, данные остались в базе».

При выключении модуля, если открыт его раздел, админа перебрасывает на `Товары`; фильтр «Точка» сбрасывается на `all`.

---

## 14. Тесты (Pest)

На каждый сервис — happy path + минимум один отказ. На каждый маршрут, меняющий данные, — фичевый тест.

- `ProductTest`: фильтр по статусу и поиску, сортировка по имени/артикулу/цене, пагинация по 25, расчёт `final = round(price * (1 - discount), 2)`.
- `ProductValidationTest`: все пять правил из §8.6, включая уникальность артикула и штрихкода.
- `Ean13Test`: контрольная цифра для `801100399` + суффиксов.
- `ModuleTest`: выключение `points` каскадом отключает `warehouses` и `productPoints`; скрытый раздел отдаёт 404 по прямому URL.
- `RightsTest`: роль `admin` неизменяема; скрытое поле не попадает в ответ `/api/v1/products`.
- `PasswordTest`: генератор даёт формат `xxxx-xxxx-NNN`; ручной пароль проходит только при выполнении всех трёх правил.

---

## 15. Чек-лист приёмки

Сначала пройди чек-лист из последнего раздела `references/craft-baseline.md`, потом этот:

**Скилл:**
- [ ] Ни одной операции над данными на клиенте: поиск, фильтры, сортировка, пагинация — запросом на сервер, фильтры в URL.
- [ ] Ни одного цвета мимо `tokens.css` — поиск по `#`, `slate-`, `gray-`, `blue-` в `resources/js` ничего не находит.
- [ ] `/api/v1` существует с первого коммита, сервисы общие с вебом.
- [ ] Ни одного `$request->validate()` в контроллере; статусы — Enum; права — Policy.
- [ ] Компоненты названы под домен (`ProductRow`, а не `DataTableRow`).
- [ ] Строка дописана в `assets/design-log.md` (§16).

**Визуал:**

- [ ] Ни одного скругления больше 2px, ни одной тени вне модалок и выезжающей панели.
- [ ] Все числа моноширинные и с `tabular-nums`; деньги в формате `1 415,88`.
- [ ] Статусы — подчёркнутый текст, а не «пилюли».
- [ ] Grid-шаблоны шапок и строк совпадают символ в символ (иначе колонки поедут).
- [ ] Шапка, табы и подвалы не скроллятся; скроллятся только тела таблиц.
- [ ] Товар со скидкой: розничная перечёркнута и серая, «со скидкой» — жирная красная.
- [ ] Полоска категории слева в строке товара тянется на всю высоту строки.
- [ ] Выключение модуля `points` убирает: раздел «Точки и склады», фильтр «Точка», колонку «Точки» у сотрудников, точку в синхронизации, выбор точек в форме сотрудника — и меняет grid соответствующих таблиц.
- [ ] `4 ТОЧКИ` на экране входа исчезает вместе с модулем `points`.
- [ ] Тройной клик по «v1.0» и логин `root` открывают `/su`.
- [ ] Экран входа переключается RU/TM полностью, включая тексты ошибок.
- [ ] Матрица прав: строка администратора не кликается, изменённые ячейки латунные до нажатия «Сохранить политику».
- [ ] Мастер импорта: исправление строки перекрашивает её в зелёный, счётчики «Готовы / Предупреждения / Ошибки» и текст кнопки пересчитываются.
- [ ] Тексты не переписаны — совпадают со спецификацией дословно.

---

## 16. Запись в журнал (Шаг 6 скилла)

После сдачи допиши в `assets/design-log.md`:

```
Проект:       ARÔME Admin
Домен:        розничная сеть парфюмерии (Туркменистан), каталог и цены
Направление:  «прайс-лист на бумаге» — тёплая бумага, волосяные линейки,
              Playfair Display + IBM Plex Sans/Mono, нулевые радиусы, без теней
Палитра:      #1B1512 / #E9E1D3 / #FAF6ED / #A2751E / #93251C / #4E6A46
Сигнатура:    строка товара как строка накладной: цветная полоса категории
              на всю высоту слева, перечёркнутая цена при скидке,
              статус подчёркнут снизу вместо «пилюли»
Занято:       тёплая бумажная палитра + Playfair — в следующих проектах не брать
```
