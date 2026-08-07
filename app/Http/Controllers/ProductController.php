<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkPriceRequest;
use App\Http\Requests\HideProductsRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductRowResource;
use App\Models\Point;
use App\Models\Product;
use App\Repositories\ProductRepository;
use App\Services\ExportService;
use App\Services\ModuleService;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductService $service,
        private readonly ModuleService $modules,
    ) {}

    public function index(Request $request): Response
    {
        $modules = $this->modules->effective();
        $filters = $this->filters($request, $modules['points']);

        return Inertia::render('Products/Index', [
            'products' => fn () => ProductRowResource::collection(
                $this->products->paginate($filters, config('aroma.per_page'), $modules['productPoints'])
            ),
            'filters' => $filters,
            'queryString' => $this->queryString($filters),
            'points' => fn () => $modules['points']
                ? Point::orderBy('id')->get(['id', 'code', 'name'])
                : [],
            'card' => fn (): ?array => $this->card($request),
        ]);
    }

    /**
     * Выгрузка текущего списка в Excel. Фильтры те же, что у таблицы: уезжает ровно то,
     * что администратор видит на экране, а не весь каталог «на всякий случай».
     */
    public function export(Request $request, ExportService $export): BinaryFileResponse
    {
        Gate::authorize('viewAny', Product::class);

        $modules = $this->modules->effective();
        $filters = $this->filters($request, $modules['points']);

        $export->record($filters, $modules['productPoints'], $this->actor());

        return response()
            ->download($export->write($filters, $modules['productPoints']), $export->fileName($filters), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = $this->service->create($request->payload(), $this->actor());

        return back()->with('toast', [
            'name' => $product->name,
            'text' => 'добавлен. Устройства получат карточку при ближайшей синхронизации.',
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->service->update($product, $request->payload(), $this->actor());

        return back();
    }

    /**
     * Удаление карточки из каталога. Подтверждение спрашивают в браузере — сюда запрос
     * приходит уже после него, и восстановить товар отсюда нельзя.
     */
    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $name = $product->name;

        $this->service->delete($product, $this->actor());

        return back()->with('toast', [
            'name' => $name,
            'text' => 'удалён из каталога. Устройства потеряют карточку при ближайшей синхронизации.',
        ]);
    }

    /**
     * Bulk price / discount edit over the current selection.
     */
    public function bulk(BulkPriceRequest $request): RedirectResponse
    {
        ['ids' => $ids, 'mode' => $mode, 'value' => $value] = $request->payload();

        $this->service->applyBulk($ids, $mode, $value, $this->actor());

        return back();
    }

    public function hide(HideProductsRequest $request): RedirectResponse
    {
        $this->service->hide($request->ids(), $this->actor());

        return back();
    }

    /**
     * A fresh unused EAN-13 for the "Сгенерировать" button in the new-product modal.
     */
    public function barcode(): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        return back()->with('toast', ['barcode' => $this->service->generateBarcode()]);
    }

    /**
     * The product card the «?product=» in the URL asks for, if it is still there.
     *
     * resolve() instead of returning the resource itself: Inertia renders a Responsable
     * through toResponse(), and a single JsonResource wraps its payload in «data» there —
     * the modal would get {data: {...}} and show nothing but empty fields.
     *
     * @return array<string, mixed>|null
     */
    private function card(Request $request): ?array
    {
        if (! $request->filled('product')) {
            return null;
        }

        $product = $this->products->find((int) $request->integer('product'));

        return $product instanceof Product
            ? (new ProductCardResource($product))->resolve()
            : null;
    }

    /**
     * @return array{q: string, point: string, status: string, sort: string, page: int}
     */
    private function filters(Request $request, bool $withPoints): array
    {
        return [
            'q' => (string) $request->query('q', ''),
            'point' => $withPoints ? (string) $request->query('point', 'all') : 'all',
            'status' => (string) $request->query('status', 'all'),
            'sort' => (string) $request->query('sort', 'name'),
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }

    /**
     * The monospaced request line printed in the table footer.
     *
     * @param  array{q: string, point: string, status: string, sort: string, page: int}  $filters
     */
    private function queryString(array $filters): string
    {
        return sprintf(
            'GET /api/v1/products?q=%s&point=%s&status=%s&sort=%s&page=%d',
            $filters['q'],
            $filters['point'],
            $filters['status'],
            $filters['sort'],
            $filters['page'],
        );
    }
}
