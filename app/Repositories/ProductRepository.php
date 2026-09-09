<?php

namespace App\Repositories;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Every list operation lives here as SQL. Nothing is filtered, sorted or sliced in the
 * browser: the table has 214 demo rows today and a real price list has tens of thousands.
 */
class ProductRepository
{
    /**
     * Sortable columns, mapped from the request key to the real column. Interpolating a
     * client-supplied column into ORDER BY without this whitelist is an injection hole.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'name' => 'name',
        'main_code' => 'main_code',
        'sku' => 'sku',
        'barcode' => 'barcode',
        'price' => 'price',
        'wholesale' => 'wholesale_price',
    ];

    /**
     * Колонки, по которым работает `?q=`. API сужает список до полей, открытых роли;
     * веб-панель ничего не передаёт и ищет по всем четырём.
     *
     * @var list<string>
     */
    private const SEARCHABLE = ['name', 'sku', 'main_code', 'barcode'];

    /**
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null, only_active?: bool, search_fields?: list<string>}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters, int $perPage, bool $withPoints): LengthAwarePaginator
    {
        $query = $this->filtered($filters, $withPoints);

        if ($withPoints) {
            $query->with(['stocks:id,product_id,point_id,qty']);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * The same list the table shows, read row by row for the export. `cursor()` keeps a
     * single row in memory at a time, so a 50 000-line price list costs the same as a page.
     *
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null}  $filters
     * @return LazyCollection<int, Product>
     */
    public function stream(array $filters, bool $withPoints): LazyCollection
    {
        return $this->filtered($filters, $withPoints)->cursor();
    }

    /**
     * Весь активный каталог целиком, без поиска, фильтров и постраничности — то, что
     * забирает приложение, когда обновляет свою локальную базу одним заходом.
     *
     * Читается пачками по тысяче строк с продвижением по id: остатки при этом
     * подгружаются одним запросом на пачку, чего `cursor()` не умеет — там связь
     * осталась бы незагруженной и `stock` уехал бы на устройство пустым.
     *
     * @return LazyCollection<int, Product>
     */
    public function streamAll(bool $withStock): LazyCollection
    {
        return Product::query()
            ->select(['id', 'main_code', 'sku', 'barcode', 'name', 'kind', 'price', 'wholesale_price', 'discount', 'discount_price', 'status'])
            ->where('status', ProductStatus::Active->value)
            ->when($withStock, fn (Builder $query) => $query->with('stocks:id,product_id,point_id,qty'))
            ->lazyById(1000);
    }

    /**
     * How many rows the current filters match — the number written into the journal
     * before the download starts.
     *
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null}  $filters
     */
    public function countMatching(array $filters, bool $withPoints): int
    {
        return $this->filtered($filters, $withPoints)->count();
    }

    /**
     * Full card for the edit modal. Stock is not part of it: the remainder is owned by the
     * warehouse and by device sync, and is never typed in by hand here.
     *
     * Ничего не найдено — это null, а не 404: ссылка с `?product=` живёт дольше карточки
     * (её удалили, её прислали в письме), и вся страница каталога из-за этого падать
     * не должна — просто не открывается модальное окно.
     */
    public function find(int $id): ?Product
    {
        return Product::with([
            'priceHistories' => fn ($query) => $query->orderByDesc('changed_at'),
        ])->find($id);
    }

    /**
     * Поиск по штрихкоду — то, что делает сканер. Точное совпадение по индексу.
     *
     * `$onlyActive` — то же правило, что и в списке: скрытый товар для приложения
     * продавца не существует, и отвечать на него надо так же, как на чужой штрихкод.
     */
    public function findByBarcode(string $barcode, bool $withStock = false, bool $onlyActive = false): ?Product
    {
        return Product::query()
            ->when($withStock, fn (Builder $query) => $query->with('stocks:id,product_id,point_id,qty'))
            ->when($onlyActive, fn (Builder $query) => $query->where('status', ProductStatus::Active->value))
            ->where('barcode', $barcode)
            ->first();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    public function whereIds(array $ids): Collection
    {
        return Product::whereIn('id', $ids)->get();
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        return Product::where('sku', $sku)
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function barcodeExists(string $barcode, ?int $exceptId = null): bool
    {
        return Product::where('barcode', $barcode)
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * Сколько карточек сейчас в каталоге — то число, которое мастер импорта называет в
     * окне подтверждения: столько уйдёт, когда прайс заменит каталог.
     */
    public function countAll(): int
    {
        return Product::count();
    }

    /**
     * Каталог целиком, под снос: прайс задаёт его полностью, и импорт начинает с
     * чистого листа, см. {@see ImportService::apply()}. Одним DELETE без выборки id —
     * условия нет, а каталог бывает в десятки тысяч строк.
     *
     * Удаление каскадное: вместе с товарами уходят история цен, остатки по точкам и
     * сканы (см. миграции этих таблиц).
     *
     * @return int сколько товаров удалено
     */
    public function deleteAll(): int
    {
        return Product::query()->delete();
    }

    /**
     * Следующий свободный код серии AA####.
     *
     * Считается только по кодам самой серии — «AA» и дальше одни цифры без ведущего
     * нуля. Прайс поставщика приносит в колонку основного кода что угодно, и такое
     * значение из счёта выбрасывается: «AA0000001» иначе читается как единица, серия
     * откатывается к «AA2», и второй новый товар того же импорта падал на уникальном
     * индексе — SQLSTATE 23505 вместо импорта.
     *
     * Проверка существования — страховка от кода, заведённого мимо серии: занять
     * уникальный индекс второй раз всё равно нельзя, и лучше поискать свободный номер
     * здесь, чем упасть на вставке.
     */
    public function nextMainCode(): string
    {
        $number = $this->highestMainCodeNumber() + 1;

        while (Product::where('main_code', 'AA'.$number)->exists()) {
            $number++;
        }

        return 'AA'.$number;
    }

    /**
     * Наибольший занятый номер серии, 1000 — если серии в каталоге ещё нет.
     *
     * Сортировка по (длина, строка) ставит наибольший код серии первым, а PHP берёт
     * первый подходящий: курсор в обычном каталоге читает одну строку, а не весь
     * список. Цифр не больше пятнадцати — так номер всегда остаётся целым числом
     * PHP, а не превращается во float с экспонентой.
     */
    private function highestMainCodeNumber(): int
    {
        $codes = Product::query()
            ->select('main_code')
            ->where('main_code', 'like', 'AA%')
            ->orderByRaw('length(main_code) desc, main_code desc')
            ->cursor();

        foreach ($codes as $product) {
            if (preg_match('/^AA([1-9]\d{0,14})$/', (string) $product->main_code, $match) === 1) {
                return (int) $match[1];
            }
        }

        return 1000;
    }

    /**
     * Filtering and sorting shared by the table, the export and the row counter — the
     * three must never disagree about what «the current list» means.
     *
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null, only_active?: bool, search_fields?: list<string>}  $filters
     * @return Builder<Product>
     */
    private function filtered(array $filters, bool $withPoints): Builder
    {
        $query = Product::query()
            ->select(['id', 'main_code', 'sku', 'barcode', 'name', 'kind', 'price', 'wholesale_price', 'discount', 'discount_price', 'status']);

        $this->applyActiveOnly($query, ($filters['only_active'] ?? false) === true);
        $this->applySearch($query, $filters['q'] ?? null, $filters['search_fields'] ?? self::SEARCHABLE);
        $this->applyStatus($query, $filters['status'] ?? null);
        $this->applyPoint($query, $filters['point'] ?? null, $withPoints);
        $this->applySort($query, $filters['sort'] ?? null);

        return $query;
    }

    /**
     * Поиск идёт только по колонкам из $fields. Роль, которой скрыли все четыре поля,
     * не находит ничего — «искать не по чему» здесь означает пустой результат, а не
     * весь каталог: иначе скрытое поле восстанавливается перебором префиксов.
     *
     * @param  Builder<Product>  $query
     * @param  list<string>  $fields
     */
    private function applySearch(Builder $query, ?string $term, array $fields): void
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < 2) {
            return;
        }

        $fields = array_values(array_intersect(self::SEARCHABLE, $fields));

        if ($fields === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $like = $this->caseInsensitiveLike();

        $query->where(function (Builder $query) use ($term, $like, $fields): void {
            foreach ($fields as $field) {
                // Название ищется подстрокой, коды — префиксом: артикул набирают с начала.
                $query->orWhere(
                    $field,
                    $field === 'sku' || $field === 'barcode' ? 'like' : $like,
                    $field === 'name' ? '%'.$term.'%' : $term.'%',
                );
            }
        });
    }

    /**
     * Postgres needs ILIKE for case-insensitive matching; SQLite (used by the test
     * suite) has no such operator and folds ASCII case in LIKE already.
     */
    private function caseInsensitiveLike(): string
    {
        return Product::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Жёсткое ограничение каталога, а не фильтр: приложение продавца видит только
     * активные карточки, и никакой параметр запроса это снять не может.
     *
     * @param  Builder<Product>  $query
     */
    private function applyActiveOnly(Builder $query, bool $onlyActive): void
    {
        if ($onlyActive) {
            $query->where('status', ProductStatus::Active->value);
        }
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyStatus(Builder $query, ?string $status): void
    {
        if (in_array($status, ProductStatus::values(), true)) {
            $query->where('status', $status);
        }
    }

    /**
     * Filtering by point only means something while per-point stock is switched on.
     *
     * @param  Builder<Product>  $query
     */
    private function applyPoint(Builder $query, ?string $point, bool $withPoints): void
    {
        if (! $withPoints || $point === null || $point === 'all' || $point === '') {
            return;
        }

        $query->whereHas('stocks', fn (Builder $stocks) => $stocks
            ->where('point_id', (int) $point)
            ->where('qty', '>', 0));
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        $sort ??= 'name';
        $descending = str_starts_with($sort, '-');
        $column = self::SORTABLE[ltrim($sort, '-')] ?? 'name';

        $query->orderBy($column, $descending ? 'desc' : 'asc')->orderBy('id');
    }
}
