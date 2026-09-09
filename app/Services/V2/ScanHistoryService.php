<?php

namespace App\Services\V2;

use App\Models\Product;
use App\Models\ProductScan;
use App\Models\User;
use App\Repositories\V2\ProductScanRepository;
use App\Services\V2\Data\ScanHistory;

/**
 * Последние просканированные товары. Историю ведёт сам сервер на успешном поиске по
 * штрихкоду {@see CatalogService::scan()}.
 *
 * Карточки собираются здесь и сейчас, а не сохраняются вместе со сканом: цена в
 * истории поэтому не отстаёт от каталога.
 */
class ScanHistoryService
{
    public function __construct(
        private readonly ProductScanRepository $scans,
        private readonly FieldAccessService $access,
    ) {}

    /**
     * Имя токена — это имя телефона, с которого пришёл запрос: приложение прислало
     * его при входе, и по нему раздел «Синхронизация» различает аппараты.
     */
    public function record(User $user, Product $product): ProductScan
    {
        return $this->scans->record($user->id, $product->id, $user->currentAccessToken()?->name);
    }

    /**
     * История принадлежит сотруднику, а не телефону: пересел на другой аппарат —
     * история переехала вместе с ним.
     */
    public function recent(User $user, mixed $requestedLimit): ScanHistory
    {
        $limit = $this->limit($requestedLimit);
        $access = $this->access->forUser($user);

        return new ScanHistory($this->scans->recentFor($user->id, $limit, $access), $limit, $access);
    }

    public function clear(User $user): void
    {
        $this->scans->clearFor($user->id);
    }

    /**
     * Сколько строк истории отдавать: запрошенное значение, зажатое конфигом. Потолок
     * жёсткий — `?limit=100000` не должен превращаться в выгрузку всей истории.
     */
    private function limit(mixed $requested): int
    {
        if ($requested === null || $requested === '') {
            return (int) config('aroma.scan_history.limit');
        }

        return min((int) config('aroma.scan_history.max'), max(1, (int) $requested));
    }
}
