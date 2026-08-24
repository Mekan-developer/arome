<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Validator;

/**
 * Step 3 of the wizard, confirmed. The rows are whatever {@see ImportController::store()}
 * sent the browser, with any in-browser "Исправить" corrections folded in — they are
 * re-validated from scratch server-side, this request only shapes the input.
 */
class ConfirmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->managesCatalog() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fileName' => ['required', 'string', 'max:255'],
            /* Laravel's own hashed-upload naming (Str::random(40,'.xlsx') — always alphanumeric.
             * Rejecting anything else keeps a client-supplied path off the filesystem outright. */
            'storedPath' => ['required', 'string', 'regex:/^imports\/[A-Za-z0-9]+\.xlsx$/'],
            'rows' => ['present', 'array'],
            'rows.*.row' => ['required', 'integer', 'min:1'],
            'rows.*.mainCode' => ['nullable', 'string', 'max:64'],
            'rows.*.sku' => ['nullable', 'string', 'max:64'],
            'rows.*.barcode' => ['nullable', 'string', 'max:64'],
            'rows.*.name' => ['nullable', 'string', 'max:512'],
            'rows.*.retail' => ['nullable', 'string', 'max:64'],
            'rows.*.discount' => ['nullable', 'string', 'max:64'],
            'rows.*.wholesale' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'storedPath.regex' => 'Файл загрузки не найден — начните заново с шага «Файл».',
            'rows.*.row.required' => 'В данных строки повреждена нумерация — начните заново с шага «Файл».',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /*
             * Skip straight past a value the regex rule already rejected: Storage::exists()
             * throws on a path that still carries "../" instead of returning false, so a
             * traversal attempt must never reach it.
             */
            if ($validator->errors()->has('storedPath')) {
                return;
            }

            $path = $this->string('storedPath')->toString();

            if (! Storage::exists($path)) {
                $validator->errors()->add('storedPath', 'Загруженный файл больше не найден на сервере — загрузите его заново.');
            }
        });
    }

    /**
     * @return array{fileName: string, storedPath: string, rows: list<array<string, mixed>>}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'fileName' => $validated['fileName'],
            'storedPath' => $validated['storedPath'],
            'rows' => array_map(fn (array $row): array => [
                'row' => (int) $row['row'],
                'mainCode' => (string) ($row['mainCode'] ?? ''),
                'sku' => (string) ($row['sku'] ?? ''),
                'barcode' => (string) ($row['barcode'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'retail' => (string) ($row['retail'] ?? ''),
                'discount' => (string) ($row['discount'] ?? ''),
                'wholesale' => (string) ($row['wholesale'] ?? ''),
            ], $validated['rows']),
        ];
    }
}
