<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\HasServerMeta;
use App\Models\ProductScan;
use App\Services\V2\Data\ScanHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Экран «Последние товары». `meta.limit` — это применённый предел, а не запрошенный:
 * приложение должно видеть, что его `?limit=` зажали потолком.
 */
class ProductScanCollection extends ResourceCollection
{
    use HasServerMeta;

    public function __construct(private readonly ScanHistory $history)
    {
        parent::__construct($history->scans);
    }

    /**
     * См. {@see ProductCollection::collects()} — ресурс строки истории тоже требует
     * права роли вторым аргументом.
     */
    protected function collects(): ?string
    {
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        return $this->collection
            ->map(fn (ProductScan $scan): array => (new ProductScanResource($scan, $this->history->access))->toArray($request))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => $this->serverMeta(['limit' => $this->history->limit])];
    }
}
