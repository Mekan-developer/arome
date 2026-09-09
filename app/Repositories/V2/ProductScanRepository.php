<?php

namespace App\Repositories\V2;

use App\Enums\ProductStatus;
use App\Models\ProductScan;
use App\Services\V2\Data\FieldAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * История сканирований продавца — то, что рисует экран «Последние товары».
 */
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
     * Скрытый товар выпадает из истории так же, как из каталога: строка скана остаётся
     * в базе для отчётов, но на устройство не уходит.
     *
     * @return Collection<int, ProductScan>
     */
    public function recentFor(int $userId, int $limit, FieldAccess $access): Collection
    {
        return ProductScan::query()
            ->with(['product' => fn ($product) => $product
                ->select(['id', 'main_code', 'sku', 'barcode', 'name', 'kind', 'price', 'wholesale_price', 'discount', 'discount_price', 'status'])
                ->when($access->withStock, fn (Builder $products) => $products->with('stocks:id,product_id,point_id,qty'))])
            ->where('user_id', $userId)
            ->whereRelation('product', 'status', ProductStatus::Active->value)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function clearFor(int $userId): void
    {
        ProductScan::where('user_id', $userId)->delete();
    }
}
