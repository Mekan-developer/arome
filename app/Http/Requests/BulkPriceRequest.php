<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BulkPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('bulkEdit', Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:products,id'],
            'mode' => ['required', Rule::in(['discount', 'percent', 'amount', 'fixed'])],
            'value' => ['required', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Ни одного товара не выбрано — отметьте строки в таблице.',
            'value.numeric' => 'Значение должно быть числом.',
        ];
    }

    /**
     * @return array{ids: list<int>, mode: string, value: float}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'ids' => array_map('intval', $validated['ids']),
            'mode' => $validated['mode'],
            'value' => (float) $validated['value'],
        ];
    }
}
