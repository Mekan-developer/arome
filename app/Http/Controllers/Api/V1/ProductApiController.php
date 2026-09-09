<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ModuleKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
use App\Http\Resources\V1\ProductScanResource;
use App\Models\Product;
use App\Models\ProductScan;
use App\Repositories\ProductRepository;
use App\Services\ModuleService;
use App\Services\RightsService;
use App\Services\ScanHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;

/**
 * Каталог, каким его читает приложение продавца. Сервисы те же, что у веб-панели —
 * различаются только формат входа и выхода.
 *
 * Скрытый товар (`status = hidden`) на устройство не уходит ни в списке, ни по
 * штрихкоду: карточка остаётся в базе и в отчётах, но для продавца её нет.
 */
class ProductApiController extends Controller
{
    /**
     * Ключ матрицы прав => колонка, по которой ищет `?q=`.
     */
    private const SEARCHABLE = [
        'name' => 'name',
        'sku' => 'sku',
        'mainCode' => 'main_code',
        'barcode' => 'barcode',
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly RightsService $rights,
        private readonly ModuleService $modules,
        private readonly ScanHistoryService $scans,
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
            'only_active' => true,
            'search_fields' => $this->searchFields($visible),
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
     * Весь каталог одним ответом: ни поиска, ни фильтров, ни постраничности — этим
     * приложение заливает свою локальную базу целиком.
     *
     * Ответ отдаётся потоком: прайс на десятки тысяч строк не собирается в памяти
     * целиком ни на стороне запроса к базе, ни при кодировании JSON.
     *
     * Два ограничения фильтрами не являются и здесь тоже в силе: скрытый товар на
     * устройство не уходит, а состав колонок по-прежнему решает матрица прав роли.
     */
    public function all(Request $request): StreamedJsonResponse
    {
        $visible = $this->visibleFields($request);

        return response()->streamJson([
            'data' => $this->products
                ->streamAll($this->withStock($visible))
                ->map(fn (Product $product): array => (new ProductResource($product, $visible))->toArray($request)),
            'meta' => [
                'server_time' => now()->toIso8601ZuluString(),
            ],
        ]);
    }

    /**
     * Один товар по штрихкоду — то, ради чего продавец подносит сканер. Успешный
     * поиск попадает в историю: отдельного вызова «залогируй скан» у приложения нет.
     */
    public function show(Request $request, string $barcode): JsonResponse
    {
        $visible = $this->visibleFields($request);
        $product = $this->products->findByBarcode($barcode, $this->withStock($visible), onlyActive: true);

        if ($product === null) {
            return response()->json([
                'error' => [
                    'code' => 'product_not_found',
                    'message' => 'Товар с таким штрихкодом не найден',
                ],
            ], 404);
        }

        $this->scans->record($request->user(), $product, $request->user()->currentAccessToken()?->name);

        return response()->json([
            'data' => (new ProductResource($product, $visible))->toArray($request),
            'meta' => ['server_time' => now()->toIso8601ZuluString()],
        ]);
    }

    /**
     * Последние просканированные товары этого продавца. Карточки собираются здесь и
     * сейчас, поэтому цена в истории не отстаёт от каталога.
     */
    public function recent(Request $request): JsonResponse
    {
        $visible = $this->visibleFields($request);
        $limit = $this->scans->limit($request->query('limit'));

        $scans = $this->scans->recent($request->user(), $limit, $this->withStock($visible));

        return response()->json([
            'data' => $scans
                ->map(fn (ProductScan $scan): array => (new ProductScanResource($scan, $visible))->toArray($request))
                ->all(),
            'meta' => [
                'limit' => $limit,
                'server_time' => now()->toIso8601ZuluString(),
            ],
        ]);
    }

    /**
     * «Очистить историю» на устройстве. Чистится история сотрудника, а не телефона:
     * пересел на другой аппарат — история переехала вместе с ним.
     */
    public function clearRecent(Request $request): JsonResponse
    {
        $this->scans->clear($request->user());

        return response()->json([
            'data' => ['cleared' => true],
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

    /**
     * Искать можно только по тем полям, которые роль и так видит в карточке — иначе
     * скрытый «Основной код» восстанавливается перебором префиксов через `?q=`.
     *
     * @param  list<string>  $visible
     * @return list<string>
     */
    private function searchFields(array $visible): array
    {
        return array_values(array_intersect_key(self::SEARCHABLE, array_flip($visible)));
    }
}
