<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SyncRequest extends FormRequest
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
            'data_version' => ['nullable', 'integer', 'min:0'],
            'app_version' => ['nullable', 'string', 'max:16'],
        ];
    }

    /**
     * Ревизия каталога, которая уже лежит на устройстве.
     */
    public function sinceVersion(): int
    {
        return (int) ($this->validated()['data_version'] ?? 0);
    }
}
