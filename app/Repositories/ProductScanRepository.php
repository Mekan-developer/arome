<?php

namespace App\Repositories;

use App\Enums\ProductStatus;
use App\Models\ProductScan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class ProductScanRepository
{
    /**
     * Отметить скан. Строка на пару «сотрудник + товар»: повторное сканирование не
     * плодит записи, а поднимает товар наверх истории и увеличивает счётчик.
     */
    public function record(int $userId, int $productId, ?string $device): ProductScan
    {
        $scan = ProductScan::firstOrNew([
            'user_id' => $userId,
            'product_id' => $productId,
        ]);

        $scan->times = ($scan->exists ? $scan->times : 0) + 1;
        $scan->device = $device;
        $scan->scanned_at = now();
        $scan->save();

        return $scan;
    }

    /**
     * История одного продавца — то, что рисует экран «Последние товары».
     *
     * Скрытый товар выпадает из истории так же, как из каталога: карточка остаётся в
     * базе, но для приложения её больше нет.
     *
     * @return Collection<int, ProductScan>
     */
    public function recentFor(int $userId, int $limit, bool $withStock = false): Collection
    {
        return $this->withProduct($withStock)
            ->where('user_id', $userId)
            ->whereRelation('product', 'status', ProductStatus::Active->value)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * История сотрудников для раздела «Синхронизация устройств»: по $limit строк на
     * каждого, за два запроса на всю страницу вместо запроса на устройство.
     *
     * @param  list<int>  $userIds
     * @return SupportCollection<int, Collection<int, ProductScan>>
     */
    public function forUsers(array $userIds, int $limit): SupportCollection
    {
        if ($userIds === []) {
            return collect();
        }

        // Оконная функция режет историю по $limit внутри каждого сотрудника — без неё
        // один активный продавец занял бы всю выборку.
        $ranked = ProductScan::query()
            ->select('product_scans.id')
            ->selectRaw('row_number() over (partition by user_id order by scanned_at desc, id desc) as rn')
            ->whereIn('user_id', $userIds)
            ->whereRelation('product', 'status', ProductStatus::Active->value);

        $ids = DB::query()->fromSub($ranked, 'ranked')->where('rn', '<=', $limit)->pluck('id');

        return $this->withProduct(false)
            ->whereIn('id', $ids)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');
    }

    public function clearFor(int $userId): void
    {
        ProductScan::where('user_id', $userId)->delete();
    }

    /**
     * @return Builder<ProductScan>
     */
    private function withProduct(bool $withStock): Builder
    {
        return ProductScan::query()->with(['product' => fn ($product) => $product
            ->select(['id', 'main_code', 'sku', 'barcode', 'name', 'kind', 'price', 'discount', 'status'])
            ->when($withStock, fn ($query) => $query->with('stocks:id,product_id,point_id,qty'))]);
    }
}
