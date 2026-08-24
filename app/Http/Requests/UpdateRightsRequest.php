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
     * Настраивается одно: какие поля карточки уходят продавцу и менеджеру. Строка
     * администратора неизменяема, поэтому в запросе её просто нет.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'roles' => ['required', 'array'],
        ];

        foreach (RightsService::editableRoles() as $role) {
            $rules['roles.'.$role] = ['sometimes', 'array'];

            foreach (RightsService::panelFields() as $field) {
                $rules['roles.'.$role.'.'.$field['key']] = ['sometimes', 'boolean'];
            }
        }

        return $rules;
    }

    /**
     * Приходит только то, что панель предлагает переключать: колонка, придержанная на
     * сервере, и роль, которой в матрице нет, из запроса выбрасываются.
     *
     * @return array<string, array<string, bool>>
     */
    public function payload(): array
    {
        $allowed = array_column(RightsService::panelFields(), 'key');
        $roles = $this->validated()['roles'];

        return collect(RightsService::editableRoles())
            ->filter(fn (string $role): bool => isset($roles[$role]) && is_array($roles[$role]))
            ->mapWithKeys(fn (string $role): array => [
                $role => collect($roles[$role])
                    ->only($allowed)
                    ->map(fn ($visible): bool => (bool) $visible)
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.required' => 'Политика пуста — нечего сохранять.',
        ];
    }
}
