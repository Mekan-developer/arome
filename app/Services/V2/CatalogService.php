<?php

namespace App\Services\V2;

use App\Models\User;
use App\Repositories\V2\ProductRepository;
use App\Services\V2\Data\CatalogCard;
use App\Services\V2\Data\CatalogPage;
use App\Services\V2\Data\CatalogQuery;
use App\Services\V2\Data\CatalogStream;
use App\Services\V2\Exceptions\ProductNotFoundException;

/**
 * Каталог, каким его читает приложение продавца: список, полная выгрузка и поиск по
 * штрихкоду.
 *
 * Все три сценария начинаются с прав роли и ими же заканчиваются — состав колонок
 * едет вместе с данными до самого ресурса, поэтому «в списке поле скрыто, а по
 * штрихкоду приходит» здесь не получается по построению.
 */
class CatalogService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly FieldAccessService $access,
        private readonly ScanHistoryService $scans,
    ) {}

    /**
     * Список каталога.
     */
    public function list(?User $user, CatalogQuery $query): CatalogPage
    {
        $access = $this->access->forUser($user);

        return new CatalogPage($this->products->paginate($query, $access), $access);
    }

    /**
     * Каталог целиком — то, чем приложение заливает свою локальную базу.
     *
     * Ни поиска, ни фильтров, ни страниц: `?q=`, `?per_page=` и прочее в этом адресе
     * просто мусор. Два ограничения фильтрами не являются и здесь в силе — скрытый
     * товар не уходит, состав колонок решает роль.
     */
    public function export(?User $user): CatalogStream
    {
        $access = $this->access->forUser($user);

        return new CatalogStream($this->products->streamActive($access), $access);
    }

    /**
     * Товар по штрихкоду. Успешный поиск попадает в историю сканирований: отдельного
     * вызова «залогируй скан» у приложения нет, и история не разъезжается с тем, что
     * продавец видел на экране.
     *
     * Неудачный — не попадает: чужой штрихкод не должен всплывать в «Последних
     * товарах» продавца.
     *
     * @throws ProductNotFoundException
     */
    public function scan(User $user, string $barcode): CatalogCard
    {
        $access = $this->access->forUser($user);
        $product = $this->products->findActiveByBarcode($barcode, $access);

        if ($product === null) {
            throw new ProductNotFoundException;
        }

        $this->scans->record($user, $product);

        return new CatalogCard($product, $access);
    }
}
