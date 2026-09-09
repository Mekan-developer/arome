<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\HasServerMeta;
use App\Models\Product;
use App\Services\V2\Data\CatalogStream;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;

/**
 * Каталог целиком, отданный потоком: строки кодируются по мере чтения из базы, и
 * прайс на десятки тысяч позиций не собирается в памяти ни на выборке, ни в JSON.
 *
 * Обычным `JsonResource` это не выражается — тот сначала строит массив целиком, — но
 * снаружи ответ выглядит так же, как остальные: `data` и `meta`.
 */
class StreamedProductCollection implements Responsable
{
    use HasServerMeta;

    public function __construct(private readonly CatalogStream $stream) {}

    public function toResponse($request): StreamedJsonResponse
    {
        return response()->streamJson([
            'data' => $this->stream->products->map(
                fn (Product $product): array => (new ProductResource($product, $this->stream->access))->toArray($request),
            ),
            'meta' => $this->serverMeta(),
        ]);
    }
}
