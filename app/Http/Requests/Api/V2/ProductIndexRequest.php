<?php

namespace App\Http\Requests\Api\V2;

use App\Services\V2\Data\CatalogQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Разбор адресной строки списка каталога. Дальше едет {@see CatalogQuery} —
 * `Request` ниже контроллера не спускается.
 */
class ProductIndexRequest extends FormRequest
{
    /**
     * Постраничность по умолчанию и потолок. Потолок жёсткий: `?per_page=100000` не
     * должен превращаться в выгрузку каталога — для неё есть `products_all`.
     */
    private const PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:64'],
            'point' => ['nullable', 'string', 'max:16'],
            'status' => ['nullable', 'string', 'max:16'],
            'sort' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Данные берутся из строки запроса, а не из тела: GET с телом — это ошибка
     * клиента, и молча принимать её не надо.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query();
    }

    public function catalogQuery(): CatalogQuery
    {
        $validated = $this->validated();

        return new CatalogQuery(
            search: $validated['q'] ?? null,
            point: $validated['point'] ?? null,
            status: $validated['status'] ?? null,
            /*
             * Порядок по умолчанию — основной код: приложение показывает товары тем же
             * порядком, что и прайс, а `?sort=` остаётся для явного запроса клиента.
             */
            sort: ($validated['sort'] ?? null) ?: 'main_code',
            perPage: $this->perPage($validated['per_page'] ?? null),
        );
    }

    /**
     * Слишком большая страница зажимается, а не отвергается: телефон в зале не должен
     * получать 422 из-за числа, которое сервер умеет исправить сам.
     */
    private function perPage(mixed $requested): int
    {
        if ($requested === null) {
            return self::PER_PAGE;
        }

        return min(self::MAX_PER_PAGE, max(1, (int) $requested));
    }
}
