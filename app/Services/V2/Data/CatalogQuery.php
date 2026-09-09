<?php

namespace App\Services\V2\Data;

use App\Http\Requests\Api\V2\ProductIndexRequest;

/**
 * Запрос списка каталога, уже разобранный из адресной строки
 * {@see ProductIndexRequest}. Ниже этого объекта строка
 * запроса не спускается: репозиторий получает значения, а не `Request`.
 */
final class CatalogQuery
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $point = null,
        public readonly ?string $status = null,
        public readonly string $sort = 'main_code',
        public readonly int $perPage = 25,
    ) {}
}
