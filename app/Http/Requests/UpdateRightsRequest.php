<?php

namespace App\Http\Requests;

use App\Services\RightsService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->managesCatalog() ?? false;
    }

    /**
     * Настраивается ровно одно: какие поля карточки уходят продавцу. Строка
     * администратора неизменяема, поэтому в запросе её просто нет.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array'],
            'fields.*' => ['boolean'],
            ...collect(RightsService::panelFields())
                ->mapWithKeys(fn (array $field): array => [
                    'fields.'.$field['key'] => ['sometimes', 'boolean'],
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function payload(): array
    {
        $allowed = array_column(RightsService::panelFields(), 'key');

        return collect($this->validated()['fields'])
            ->only($allowed)
            ->map(fn ($visible): bool => (bool) $visible)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fields.required' => 'Политика пуста — нечего сохранять.',
        ];
    }
}
