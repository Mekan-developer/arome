<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\HasServerMeta;
use App\Models\Product;
use App\Services\V2\Data\CatalogPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Страница каталога. Мета собирается своя, а не стандартная от пагинатора: приложению
 * нужен признак «есть ли ещё», а не `total` и `last_page` — их у v2 нет намеренно,
 * COUNT по всему прайсу на каждую прокрутку экрана не окупается.
 */
class ProductCollection extends ResourceCollection
{
    use HasServerMeta;

    public function __construct(private readonly CatalogPage $page)
    {
        parent::__construct(collect($page->products->items()));
    }

    /**
     * Обёртывание элементов — ручное: `ProductResource` требует ещё и права роли,
     * а угаданный фреймворком `collects` создаёт ресурс одним аргументом.
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
            ->map(fn (Product $product): array => (new ProductResource($product, $this->page->access))->toArray($request))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => $this->serverMeta([
                'per_page' => $this->page->products->perPage(),
                'current_page' => $this->page->products->currentPage(),
                'has_more' => $this->page->products->hasMorePages(),
            ]),
        ];
    }
}
