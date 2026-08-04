<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the product table. Only what the row draws — the card loads separately.
 *
 * @mixin Product
 */
class ProductRowResource extends JsonResource
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
            'discount' => (float) $this->discount,
            'final' => ProductService::finalPrice((float) $this->price, (float) $this->discount),
            'status' => $this->status,
        ];
    }
}
