<?php

namespace App\Http\Resources;

use App\Models\PriceHistory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The edit modal: card fields and the price history.
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mainCode' => $this->main_code,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'kind' => $this->kind,
            'price' => (float) $this->price,
            'wholesalePrice' => $this->wholesale_price === null ? null : (float) $this->wholesale_price,
            'discount' => (float) $this->discount,
            'discountPercent' => round((float) $this->discount * 100, 2),
            /* Цена со скидкой, названная прайсом напрямую, — в форме её не правят, но
             * карточка обязана показать, по какой цене товар продаётся на самом деле. */
            'discountPrice' => $this->discount_price === null ? null : (float) $this->discount_price,
            'final' => $this->finalPrice(),
            'status' => $this->status,
            'history' => $this->whenLoaded('priceHistories', fn () => $this->priceHistories
                ->map(fn (PriceHistory $entry): array => [
                    'date' => $entry->changed_at->format('d.m.Y'),
                    'author' => $entry->author,
                    'reason' => $entry->reason,
                    'from' => (float) $entry->price_from,
                    'to' => (float) $entry->price_to,
                    'rising' => (float) $entry->price_to > (float) $entry->price_from,
                ])->values()),
        ];
    }
}
