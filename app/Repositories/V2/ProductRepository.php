<?php

namespace App\Repositories\V2;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\V2\Data\CatalogQuery;
use App\Services\V2\Data\FieldAccess;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * Каталог глазами приложения продавца. Слой знает только SQL: что показывать роли и
 * по каким полям пускать поиск, решено выше — сюда это приезжает готовым
 * {@see FieldAccess}.
 *
 * Отдельный от панели репозиторий именно поэтому: у веб-каталога есть скрытые товары,
 * черновики и правки, а здесь активные карточки — единственное, что вообще читается,
 * и «показать скрытое» не выражается никаким параметром запроса.
 */
class ProductRepository
{
    /**
     * Колонки сортировки, от ключа запроса к настоящей колонке. Подставлять в ORDER BY
     * то, что прислал клиент, без этого списка — дыра.
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
     * Колонки, по которым вообще бывает поиск. Роль сужает этот список ещё раз.
     *
     * @var list<string>
     */
    private const SEARCHABLE = ['name', 'sku', 'main_code', 'barcode'];

    /**
     * Колонки карточки. Списком, а не `select *`: цена со скидкой и остаток тянут за
     * собой лишние данные, а прайс читается пачками по тысяче строк.
     *
     * @var list<string>
     */
    private const COLUMNS = ['id', 'main_code', 'sku', 'barcode', 'name', 'kind', 'price', 'wholesale_price', 'discount', 'discount_price', 'status'];

    /**
     * Страница списка. Постранично — `simplePaginate`: приложению нужен только признак
     * «есть ли ещё», а COUNT по всему прайсу на каждую прокрутку экрана не нужен.
     *
     * @return Paginator<int, Product>
     */
    public function paginate(CatalogQuery $query, FieldAccess $access): Paginator
    {
        return $this->filtered($query, $access)
            ->when($access->withStock, fn (Builder $products) => $products->with('stocks:id,product_id,point_id,qty'))
            ->simplePaginate($query->perPage);
    }

    /**
     * Весь активный каталог: ни поиска, ни фильтров, ни страниц — этим приложение
     * заливает свою локальную базу целиком.
     *
     * Пачками по тысяче строк с продвижением по id, а не `cursor()`: остатки при этом
     * подгружаются одним запросом на пачку, иначе связь осталась бы незагруженной и
     * `stock` уехал бы на устройство пустым.
     *
     * @return LazyCollection<int, Product>
     */
    public function streamActive(FieldAccess $access): LazyCollection
    {
        return $this->active()
            ->when($access->withStock, fn (Builder $products) => $products->with('stocks:id,product_id,point_id,qty'))
            ->lazyById(1000);
    }

    /**
     * Поиск по штрихкоду — то, ради чего продавец подносит сканер. Точное совпадение
     * по индексу, и только среди активных карточек.
     */
    public function findActiveByBarcode(string $barcode, FieldAccess $access): ?Product
    {
        return $this->active()
            ->when($access->withStock, fn (Builder $products) => $products->with('stocks:id,product_id,point_id,qty'))
            ->where('barcode', $barcode)
            ->first();
    }

    /**
     * @return Builder<Product>
     */
    private function filtered(CatalogQuery $query, FieldAccess $access): Builder
    {
        $products = $this->active();

        $this->applySearch($products, $query->search, $access->searchColumns);
        $this->applyStatus($products, $query->status);
        $this->applyPoint($products, $query->point, $access->withStock);
        $this->applySort($products, $query->sort);

        return $products;
    }

    /**
     * Скрытый товар для приложения не существует. Это не фильтр, а граница выборки:
     * снять её параметром запроса нельзя, потому что параметра нет.
     *
     * @return Builder<Product>
     */
    private function active(): Builder
    {
        return Product::query()
            ->select(self::COLUMNS)
            ->where('status', ProductStatus::Active->value);
    }

    /**
     * Поиск идёт только по колонкам из $columns. Роль, которой скрыли все четыре поля,
     * не находит ничего: иначе скрытый «Основной код» восстанавливается перебором
     * префиксов через `?q=`.
     *
     * @param  Builder<Product>  $products
     * @param  list<string>  $columns
     */
    private function applySearch(Builder $products, ?string $term, array $columns): void
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < 2) {
            return;
        }

        $columns = array_values(array_intersect(self::SEARCHABLE, $columns));

        if ($columns === []) {
            $products->whereRaw('0 = 1');

            return;
        }

        $like = $this->caseInsensitiveLike();

        $products->where(function (Builder $products) use ($term, $like, $columns): void {
            foreach ($columns as $column) {
                // Название ищется подстрокой, коды — префиксом: артикул набирают с начала.
                $products->orWhere(
                    $column,
                    $column === 'sku' || $column === 'barcode' ? 'like' : $like,
                    $column === 'name' ? '%'.$term.'%' : $term.'%',
                );
            }
        });
    }

    /**
     * Postgres ищет без учёта регистра через ILIKE; SQLite из тестов такого оператора
     * не знает и складывает регистр ASCII прямо в LIKE.
     */
    private function caseInsensitiveLike(): string
    {
        return Product::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Фильтр статуса поверх активной выборки: `?status=hidden` — это пустой ответ, а
     * не способ увидеть скрытое.
     *
     * @param  Builder<Product>  $products
     */
    private function applyStatus(Builder $products, ?string $status): void
    {
        if (in_array($status, ProductStatus::values(), true)) {
            $products->where('status', $status);
        }
    }

    /**
     * Фильтр по точке что-то значит, только пока остатки ведутся по точкам.
     *
     * @param  Builder<Product>  $products
     */
    private function applyPoint(Builder $products, ?string $point, bool $withStock): void
    {
        if (! $withStock || $point === null || $point === 'all' || $point === '') {
            return;
        }

        $products->whereHas('stocks', fn (Builder $stocks) => $stocks
            ->where('point_id', (int) $point)
            ->where('qty', '>', 0));
    }

    /**
     * @param  Builder<Product>  $products
     */
    private function applySort(Builder $products, string $sort): void
    {
        $descending = str_starts_with($sort, '-');
        $column = self::SORTABLE[ltrim($sort, '-')] ?? 'main_code';

        $products->orderBy($column, $descending ? 'desc' : 'asc')->orderBy('id');
    }
}
