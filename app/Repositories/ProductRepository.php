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
        'sku' => 'sku',
        'price' => 'price',
    ];

    /**
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null}  $filters
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
     */
    public function find(int $id): Product
    {
        return Product::with([
            'priceHistories' => fn ($query) => $query->orderByDesc('changed_at'),
        ])->findOrFail($id);
    }

    /**
     * Поиск по штрихкоду — то, что делает сканер. Точное совпадение по индексу.
     */
    public function findByBarcode(string $barcode, bool $withStock = false): ?Product
    {
        return Product::query()
            ->when($withStock, fn (Builder $query) => $query->with('stocks:id,product_id,point_id,qty'))
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
     * Every product that could collide with an uploaded price list, in one query. The
     * import validates thousands of rows against sku/barcode/main_code uniqueness —
     * a query per row per key would be three queries times the row count.
     *
     * @param  list<string>  $skus
     * @param  list<string>  $barcodes
     * @param  list<string>  $mainCodes
     * @return Collection<int, Product>
     */
    public function matchingImportKeys(array $skus, array $barcodes, array $mainCodes): Collection
    {
        if ($skus === [] && $barcodes === [] && $mainCodes === []) {
            return new Collection;
        }

        return Product::query()
            ->select(['id', 'main_code', 'sku', 'barcode'])
            ->where(function (Builder $query) use ($skus, $barcodes, $mainCodes): void {
                $query->whereIn('sku', $skus)
                    ->orWhereIn('barcode', $barcodes)
                    ->orWhereIn('main_code', $mainCodes);
            })
            ->get();
    }

    /**
     * Next free main code in the AA#### series.
     */
    public function nextMainCode(): string
    {
        $last = Product::orderByRaw('length(main_code) desc, main_code desc')->value('main_code');

        return 'AA'.(((int) substr((string) $last, 2) ?: 1000) + 1);
    }

    /**
     * Filtering and sorting shared by the table, the export and the row counter — the
     * three must never disagree about what «the current list» means.
     *
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null}  $filters
     * @return Builder<Product>
     */
    private function filtered(array $filters, bool $withPoints): Builder
    {
        $query = Product::query()
            ->select(['id', 'main_code', 'sku', 'barcode', 'name', 'kind', 'price', 'discount', 'status']);

        $this->applySearch($query, $filters['q'] ?? null);
        $this->applyStatus($query, $filters['status'] ?? null);
        $this->applyPoint($query, $filters['point'] ?? null, $withPoints);
        $this->applySort($query, $filters['sort'] ?? null);

        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < 2) {
            return;
        }

        $like = $this->caseInsensitiveLike();

        $query->where(function (Builder $query) use ($term, $like): void {
            $query->where('name', $like, '%'.$term.'%')
                ->orWhere('sku', 'like', $term.'%')
                ->orWhere('main_code', $like, $term.'%')
                ->orWhere('barcode', 'like', $term.'%');
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
