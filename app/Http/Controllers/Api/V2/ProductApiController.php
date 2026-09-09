<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ProductIndexRequest;
use App\Http\Requests\Api\V2\RecentScansRequest;
use App\Http\Resources\V2\AcknowledgementResource;
use App\Http\Resources\V2\ProductCollection;
use App\Http\Resources\V2\ProductResource;
use App\Http\Resources\V2\ProductScanCollection;
use App\Http\Resources\V2\StreamedProductCollection;
use App\Services\V2\CatalogService;
use App\Services\V2\ScanHistoryService;
use Illuminate\Http\Request;

/**
 * Каталог для приложения продавца.
 *
 * Контроллер здесь — только край HTTP: разобрать запрос, позвать сервис, отдать
 * ресурс. Права роли, порядок сортировки, запись скана и форма отказа живут в
 * {@see CatalogService} — версии API меняют формат ответа, а не правила.
 */
class ProductApiController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly ScanHistoryService $scans,
    ) {}

    public function index(ProductIndexRequest $request): ProductCollection
    {
        return new ProductCollection(
            $this->catalog->list($request->user(), $request->catalogQuery()),
        );
    }

    /**
     * Выгрузка каталога целиком — отдельный путь, а не режим `index`: у списка свой
     * договор с приложением (страницы, фильтры, `meta.has_more`).
     */
    public function all(Request $request): StreamedProductCollection
    {
        return new StreamedProductCollection($this->catalog->export($request->user()));
    }

    public function show(Request $request, string $barcode): ProductResource
    {
        return ProductResource::forCard($this->catalog->scan($request->user(), $barcode));
    }

    public function recent(RecentScansRequest $request): ProductScanCollection
    {
        return new ProductScanCollection(
            $this->scans->recent($request->user(), $request->requestedLimit()),
        );
    }

    public function clearRecent(Request $request): AcknowledgementResource
    {
        $this->scans->clear($request->user());

        return new AcknowledgementResource(['cleared' => true]);
    }
}
