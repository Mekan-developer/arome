<?php

namespace App\Http\Resources\V1;

use App\Models\ProductScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка истории сканирований. Карточка лежит отдельным ключом `product`, а не
 * подмешивается к мета-данным скана: у неё своя матрица прав и свой формат, и
 * смешивать их значит однажды сломать одно, меняя другое.
 *
 * @mixin ProductScan
 */
class ProductScanResource extends JsonResource
{
    /**
     * @param  list<string>  $visibleFields
     */
    public function __construct($resource, private readonly array $visibleFields = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'scanned_at' => $this->scanned_at->toIso8601ZuluString(),
            'times' => (int) $this->times,
            'product' => (new ProductResource($this->product, $this->visibleFields))->toArray($request),
        ];
    }
}
