<?php

namespace App\Http\Resources\V2;

use App\Models\ProductScan;
use App\Services\V2\Data\FieldAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка истории сканирований. Карточка лежит отдельным ключом `product`, а не
 * подмешивается к данным скана: у неё своя матрица прав и свой формат, и смешивать
 * их значит однажды сломать одно, меняя другое.
 *
 * @mixin ProductScan
 */
class ProductScanResource extends JsonResource
{
    public function __construct(ProductScan $scan, private readonly FieldAccess $access)
    {
        parent::__construct($scan);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'scanned_at' => $this->scanned_at->toIso8601ZuluString(),
            'times' => (int) $this->times,
            'product' => (new ProductResource($this->product, $this->access))->toArray($request),
        ];
    }
}
