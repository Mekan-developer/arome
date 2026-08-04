<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ModuleKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
use App\Repositories\ProductRepository;
use App\Services\ModuleService;
use App\Services\RightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Каталог, каким его читает приложение продавца. Сервисы те же, что у веб-панели —
 * различаются только формат входа и выхода.
 */
class ProductApiController extends Controller
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly RightsService $rights,
        private readonly ModuleService $modules,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $visible = $this->visibleFields($request);
        $withStock = $this->withStock($visible);
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $page = $this->products->paginate([
            'q' => $request->query('q'),
            'point' => $request->query('point'),
            'status' => $request->query('status'),
            'sort' => $request->query('sort'),
        ], $perPage, $withStock);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn ($product): array => (new ProductResource($product, $visible))->toArray($request))
                ->all(),
            'meta' => [
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'has_more' => $page->hasMorePages(),
                'server_time' => now()->toIso8601ZuluString(),
            ],
        ]);
    }

    /**
     * Один товар по штрихкоду — то, ради чего продавец подносит сканер.
     */
    public function show(Request $request, string $barcode): JsonResponse
    {
        $visible = $this->visibleFields($request);
        $product = $this->products->findByBarcode($barcode, $this->withStock($visible));

        if ($product === null) {
            return response()->json([
                'error' => [
                    'code' => 'product_not_found',
                    'message' => 'Товар с таким штрихкодом не найден',
                ],
            ], 404);
        }

        return response()->json([
            'data' => (new ProductResource($product, $visible))->toArray($request),
            'meta' => ['server_time' => now()->toIso8601ZuluString()],
        ]);
    }

    /**
     * @return list<string>
     */
    private function visibleFields(Request $request): array
    {
        return $this->rights->visibleFields($request->user()?->role->value ?? 'seller');
    }

    /**
     * Остаток уходит на устройство, только если он и разбит по точкам, и открыт роли.
     *
     * @param  list<string>  $visible
     */
    private function withStock(array $visible): bool
    {
        return $this->modules->enabled(ModuleKey::ProductPoints->value) && in_array('stock', $visible, true);
    }
}
