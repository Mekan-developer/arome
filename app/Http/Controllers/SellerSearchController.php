<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\V2\ProductIndexRequest;
use App\Http\Resources\V2\ProductCollection;
use App\Http\Resources\V2\ProductResource;
use App\Models\Point;
use App\Services\ModuleService;
use App\Services\V2\CatalogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Веб-версия мобильного приложения продавца: тот же каталог, те же права роли на
 * поля, тот же {@see CatalogService} — контроллер лишь меняет транспорт с токена на
 * сессию.
 */
class SellerSearchController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly ModuleService $modules,
    ) {}

    public function show(): Response
    {
        $productPoints = $this->modules->enabled('productPoints');

        return Inertia::render('SellerSearch', [
            'points' => fn () => $productPoints ? Point::orderBy('id')->get(['id', 'name']) : [],
        ]);
    }

    public function products(ProductIndexRequest $request): ProductCollection
    {
        return new ProductCollection(
            $this->catalog->list($request->user(), $request->catalogQuery()),
        );
    }

    /**
     * Найденный штрихкод попадает в историю сканирований продавца — как и в
     * мобильном приложении, это часть {@see CatalogService::scan()}, а не отдельный шаг.
     */
    public function barcode(Request $request, string $barcode): ProductResource
    {
        return ProductResource::forCard($this->catalog->scan($request->user(), $barcode));
    }
}
