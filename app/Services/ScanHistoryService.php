<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductScan;
use App\Models\User;
use App\Repositories\ProductScanRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * История последних просканированных товаров. Пишется самим сервером на успешном
 * поиске по штрихкоду — приложению не нужен отдельный вызов «залогируй скан», и
 * история не разъезжается с тем, что продавец на самом деле видел на экране.
 */
class ScanHistoryService
{
    public function __construct(
        private readonly ProductScanRepository $scans,
    ) {}

    public function record(User $user, Product $product, ?string $device): ProductScan
    {
        return $this->scans->record($user->id, $product->id, $device);
    }

    /**
     * @return Collection<int, ProductScan>
     */
    public function recent(User $user, int $limit, bool $withStock = false): Collection
    {
        return $this->scans->recentFor($user->id, $limit, $withStock);
    }

    public function clear(User $user): void
    {
        $this->scans->clearFor($user->id);
    }

    /**
     * Сколько строк истории отдавать: запрошенное значение, зажатое конфигом.
     */
    public function limit(mixed $requested): int
    {
        $default = (int) config('aroma.scan_history.limit');

        if ($requested === null || $requested === '') {
            return $default;
        }

        return min((int) config('aroma.scan_history.max'), max(1, (int) $requested));
    }
}
