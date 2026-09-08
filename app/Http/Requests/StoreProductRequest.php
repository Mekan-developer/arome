<?php

namespace App\Http\Requests;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'price' => ['required', 'numeric', 'gt:0'],
            'wholesale_price' => ['nullable', 'numeric', 'gte:0'],
            'discount' => ['required', 'numeric', 'between:0,90'],
            'status' => ['required', Rule::in(ProductStatus::values())],
            'kind' => ['nullable', Rule::in(['PARFUM', 'EDP', 'EDT', 'CARE'])],
        ];
    }

    /**
     * Wording is part of the design and is reproduced verbatim from §8.6.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Не заполнена номенклатура — без названия продавец не поймёт, что за товар.',
            'barcode.max' => 'Штрихкод не длиннее 64 символов.',
            'price.required' => 'Розничная цена должна быть больше нуля.',
            'price.numeric' => 'Розничная цена должна быть больше нуля.',
            'price.gt' => 'Розничная цена должна быть больше нуля.',
            'wholesale_price.numeric' => 'Оптовая цена должна быть числом — её видит менеджер вместо розничной.',
            'wholesale_price.gte' => 'Оптовая цена не может быть отрицательной.',
            'discount.between' => 'Скидка допустима от 0 до 90 %.',
            'discount.numeric' => 'Скидка допустима от 0 до 90 %.',
        ];
    }

    /**
     * The form sends the discount as a percentage; the catalogue stores a fraction.
     * Пустые артикул и штрихкод уходят в базу как NULL, а не пустой строкой: так их
     * не путает поиск и не склеивает сопоставление при импорте.
     *
     * @return array{name: string, sku: string|null, barcode: string|null, price: float, wholesale_price: float|null, discount: float, status: string, kind: string}
     */
    public function payload(): array
    {
        $validated = $this->validated();
        $barcode = trim((string) ($validated['barcode'] ?? ''));
        $sku = trim((string) ($validated['sku'] ?? ''));

        return [
            'name' => $validated['name'],
            'sku' => $sku !== '' ? $sku : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'price' => (float) $validated['price'],
            'wholesale_price' => isset($validated['wholesale_price']) ? (float) $validated['wholesale_price'] : null,
            'discount' => round((float) $validated['discount'] / 100, 4),
            'status' => $validated['status'],
            'kind' => $validated['kind'] ?? 'EDT',
        ];
    }
}
