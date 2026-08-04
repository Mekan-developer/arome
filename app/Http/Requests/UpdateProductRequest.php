<?php

namespace App\Http\Requests;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->isSuperadmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('product')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'main_code' => ['required', 'string', 'max:16', Rule::unique('products', 'main_code')->ignore($id)],
            'sku' => ['required', 'regex:/^\d{4,}$/', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['required', 'regex:/^\d{13}$/', Rule::unique('products', 'barcode')->ignore($id)],
            'price' => ['required', 'numeric', 'gt:0'],
            'discount' => ['required', 'numeric', 'between:0,90'],
            'status' => ['required', Rule::in(ProductStatus::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Не заполнена номенклатура — без названия продавец не поймёт, что за товар.',
            'sku.required' => 'Артикул должен быть числом из 4 и более цифр — по нему сопоставляется прайс при импорте.',
            'sku.regex' => 'Артикул должен быть числом из 4 и более цифр — по нему сопоставляется прайс при импорте.',
            'sku.unique' => 'Артикул '.$this->input('sku').' уже есть в каталоге.',
            'barcode.required' => 'Штрихкод должен быть EAN-13 — ровно 13 цифр, иначе сканер в зале не найдёт товар.',
            'barcode.regex' => 'Штрихкод должен быть EAN-13 — ровно 13 цифр, иначе сканер в зале не найдёт товар.',
            'barcode.unique' => 'Штрихкод '.$this->input('barcode').' уже занят другим товаром.',
            'price.required' => 'Розничная цена должна быть больше нуля.',
            'price.numeric' => 'Розничная цена должна быть больше нуля.',
            'price.gt' => 'Розничная цена должна быть больше нуля.',
            'discount.between' => 'Скидка допустима от 0 до 90 %.',
            'discount.numeric' => 'Скидка допустима от 0 до 90 %.',
        ];
    }

    /**
     * @return array{name: string, main_code: string, sku: string, barcode: string, price: float, discount: float, status: string}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'main_code' => $validated['main_code'],
            'sku' => (string) $validated['sku'],
            'barcode' => (string) $validated['barcode'],
            'price' => (float) $validated['price'],
            'discount' => round((float) $validated['discount'] / 100, 4),
            'status' => $validated['status'],
        ];
    }
}
