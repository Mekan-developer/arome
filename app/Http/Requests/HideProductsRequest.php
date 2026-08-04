<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * «Скрыть из продажи» для отмеченных строк. Отдельный реквест, а не BulkPriceRequest:
 * здесь нет ни режима, ни значения, и смягчать их правила ради переиспользования
 * значило бы ослабить валидацию массовой правки цены.
 */
class HideProductsRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Ни одного товара не выбрано — отметьте строки в таблице.',
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_map('intval', $this->validated()['ids']);
    }
}
