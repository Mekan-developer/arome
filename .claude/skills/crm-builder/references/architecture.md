# Архитектура backend

Стек: Laravel 11+, PostgreSQL, Redis, Inertia + Vue 3, Docker, Laravel Reverb для реального времени. Стек фиксирован и не обсуждается в начале проекта — альтернативы не предлагаются.

Следствие для бэкенда: Inertia — не API. Контроллер возвращает `Inertia::render` с готовыми к отрисовке данными, а не JSON-ресурс «на все случаи». Отдавай ровно те поля, которые нужны этой странице, и разбивай пропсы так, чтобы список можно было перезагрузить отдельно от сводки (`only` на фронте, ленивые пропсы и `Inertia::defer` на бэкенде). Подробности со стороны Vue — `references/frontend-vue.md`.

## Слои

```
routes/web.php
  └── Controller        тонкий: принял Form Request → позвал Service → вернул Inertia/Resource
        └── Service     вся бизнес-логика, транзакции, события
              └── Repository   запросы к БД, скоупы, фильтры
                    └── Model  связи, касты, enum-атрибуты
```

Правила, которые ломать не стоит:

- В контроллере нет ни `DB::`, ни `Model::query()`, ни `$request->validate()`. Только вызов сервиса и формирование ответа.
- Сервис не знает про HTTP: не принимает `Request`, не возвращает `redirect()`. Принимает DTO, возвращает модель/DTO/коллекцию, бросает доменные исключения.
- Репозиторий не содержит бизнес-правил. Только выборка, фильтрация, сортировка, пагинация.
- Транзакция открывается в сервисе, а не в контроллере и не в репозитории.
- Побочные эффекты (уведомления, вебхуки, пересчёт) — через события и очереди, не внутри основного метода.

## Структура каталогов

```
app/
├── Domain/
│   ├── Deal/
│   │   ├── Models/Deal.php
│   │   ├── Enums/DealStatus.php
│   │   ├── Data/CreateDealData.php
│   │   ├── Services/DealService.php
│   │   ├── Repositories/DealRepository.php
│   │   ├── Events/DealWon.php
│   │   └── Exceptions/DealAlreadyClosedException.php
│   ├── Client/
│   └── Task/
├── Http/
│   ├── Controllers/DealController.php
│   ├── Requests/Deal/StoreDealRequest.php
│   └── Resources/DealResource.php
└── Policies/DealPolicy.php
```

Названия папок под домен: в автосервисе `RepairOrder`, в клинике `Appointment`. «Deal» — только если у заказчика реально продажи.

## Каркас сервиса

```php
final readonly class DealService
{
    public function __construct(
        private DealRepository $deals,
        private ActivityLogger $log,
    ) {}

    public function create(CreateDealData $data, User $author): Deal
    {
        return DB::transaction(function () use ($data, $author) {
            $deal = $this->deals->create($data, $author);
            $this->log->record($deal, 'created', $author);
            event(new DealCreated($deal));

            return $deal;
        });
    }

    public function changeStage(Deal $deal, DealStage $stage, User $actor): Deal
    {
        if ($deal->isClosed()) {
            throw new DealAlreadyClosedException($deal);
        }
        // ...
    }
}
```

## Тесты (Pest)

На каждый сервисный метод — минимум два теста: успешный путь и один отказ.

```php
it('переводит сделку на следующий этап', function () {
    $deal = Deal::factory()->create(['stage' => DealStage::New]);

    app(DealService::class)->changeStage($deal, DealStage::Negotiation, user());

    expect($deal->refresh()->stage)->toBe(DealStage::Negotiation);
});

it('не даёт менять этап у закрытой сделки', function () {
    $deal = Deal::factory()->closed()->create();

    expect(fn () => app(DealService::class)->changeStage($deal, DealStage::Negotiation, user()))
        ->toThrow(DealAlreadyClosedException::class);
});
```

Плюс feature-тест на каждый маршрут, который меняет данные: проверяем код ответа, запись в БД и что чужой пользователь получает 403.

## База данных

- Все статусы — PHP enum + `string` колонка с check-констрейнтом или отдельная таблица справочника, если заказчик будет их менять.
- Индексы: на каждый внешний ключ, на поля фильтрации (`status`, `assigned_to`, `created_at`), составные — под реальные запросы списка.
- Мягкое удаление там, где заказчик захочет «вернуть как было» — почти всегда в CRM.
- История изменений: одна таблица `activity_log` с полиморфной связью, пишется из сервиса. Заказчики спрашивают «кто поменял» в 100% случаев.
- Денормализованные счётчики (сумма сделок клиента, число открытых заявок) — обновлять в событиях, не считать на каждый рендер списка.

## Многопользовательность и права

- Policy на каждую модель, проверка через `authorize()` в контроллере или `can:` в маршруте.
- Роли — простая таблица ролей и разрешений; spatie/laravel-permission уместен, но справочник ролей должен быть под домен: «мастер», «приёмщик», «диспетчер», а не «user/admin/manager».
- Видимость данных (менеджер видит только своих клиентов) — глобальный скоуп в репозитории, а не фильтр в контроллере.

## Реальное время

Laravel Reverb для: новая заявка на экране диспетчера, изменение статуса, счётчик непрочитанных, присутствие сотрудников. Каналы приватные, авторизация через `channels.php`. На фронте — `useEcho` в composable, а не подписки внутри компонентов страниц.

## Производительность и API

Вынесено в отдельный файл — `references/data-and-api.md`: пагинация без `COUNT`, поиск по триграммным индексам, нормализация телефона, белый список колонок сортировки, агрегаты через `filter (where ...)`, частичные индексы, бюджет времени ответа и версионирование API.

Два правила оттуда, которые определяют всё остальное: любая операция над данными выполняется на сервере, а API версионируется с первого дня.

## Docker

Минимум: `app` (php-fpm), `nginx`, `postgres`, `redis`, `queue` (worker), `reverb`, `node` для сборки. `.env.example` заполнен, `make up` / `make test` в Makefile — заказчик и коллега должны поднять проект одной командой.
