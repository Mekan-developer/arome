<?php

namespace App\Services\V2\Data;

use App\Models\ProductScan;
use Illuminate\Database\Eloquent\Collection;

/**
 * История сканирований продавца. `limit` возвращается в ответе: приложение должно
 * видеть, что его `?limit=` зажали потолком, а не молча отдали меньше строк.
 */
final class ScanHistory
{
    /**
     * @param  Collection<int, ProductScan>  $scans
     */
    public function __construct(
        public readonly Collection $scans,
        public readonly int $limit,
        public readonly FieldAccess $access,
    ) {}
}
