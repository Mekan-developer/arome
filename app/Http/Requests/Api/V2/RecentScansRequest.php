<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Запрос истории сканирований. Потолок предела задаёт сервис — здесь только проверка,
 * что прислали число.
 */
class RecentScansRequest extends FormRequest
{
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
            'limit' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query();
    }

    public function requestedLimit(): ?int
    {
        $limit = $this->validated()['limit'] ?? null;

        return $limit === null ? null : (int) $limit;
    }
}
