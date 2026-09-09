<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['main_code', 'sku', 'barcode', 'name', 'kind', 'price', 'wholesale_price', 'discount', 'discount_price', 'status'])]
class Product extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'discount' => 'decimal:4',
            'discount_price' => 'decimal:2',
            'status' => ProductStatus::class,
        ];
    }

    /**
     * @return HasMany<ProductStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    /**
     * @return HasMany<PriceHistory, $this>
     */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    /**
     * Цена, которую продавец назовёт покупателю.
     */
    public function finalPrice(): float
    {
        return ProductService::finalPrice(
            (float) $this->price,
            (float) $this->discount,
            $this->discount_price === null ? null : (float) $this->discount_price,
        );
    }
}
