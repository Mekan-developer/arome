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
     * Оператор видит эти строки в уведомлении, поэтому ни одно правило не остаётся с
     * английским текстом фреймворка: любой сбой здесь — это «мастер потерял состояние»,
     * и ответ всегда один — вернуться на шаг «Файл» и загрузить прайс заново.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $restart = 'Начните заново с шага «Файл»: загрузите прайс ещё раз.';

        return [
            'fileName.required' => 'Мастер импорта потерял имя файла. '.$restart,
            'fileName.string' => 'Мастер импорта потерял имя файла. '.$restart,
            'fileName.max' => 'Слишком длинное имя файла — не больше 255 символов. Переименуйте прайс и загрузите заново.',
            'storedPath.required' => 'Мастер импорта потерял загруженный файл. '.$restart,
            'storedPath.string' => 'Файл прайса не сохранился на сервере, импортировать нечего. '.$restart,
            'storedPath.regex' => 'Файл загрузки не найден — начните заново с шага «Файл».',
            'rows.present' => 'Мастер импорта не передал строки прайса. '.$restart,
            'rows.array' => 'Строки прайса пришли в неизвестном виде. '.$restart,
            'rows.*.row.required' => 'В данных строки повреждена нумерация — начните заново с шага «Файл».',
            'rows.*.row.integer' => 'В данных строки повреждена нумерация — начните заново с шага «Файл».',
            'rows.*.row.min' => 'В данных строки повреждена нумерация — начните заново с шага «Файл».',
            'rows.*.mainCode.string' => 'Основной код в строке :position испорчен. '.$restart,
            'rows.*.mainCode.max' => 'Основной код в строке :position длиннее 64 символов — сократите его и повторите импорт.',
            'rows.*.sku.string' => 'Артикул в строке :position испорчен. '.$restart,
            'rows.*.sku.max' => 'Артикул в строке :position длиннее 64 символов — сократите его и повторите импорт.',
            'rows.*.barcode.string' => 'Штрихкод в строке :position испорчен. '.$restart,
            'rows.*.barcode.max' => 'Штрихкод в строке :position длиннее 64 символов — в нём должно быть 13 цифр.',
            'rows.*.name.string' => 'Номенклатура в строке :position испорчена. '.$restart,
            'rows.*.name.max' => 'Номенклатура в строке :position длиннее 512 символов — сократите название.',
            'rows.*.retail.string' => 'Розничная цена в строке :position испорчена. '.$restart,
            'rows.*.retail.max' => 'Розничная цена в строке :position длиннее 64 символов — оставьте только число.',
            'rows.*.discount.string' => 'Скидка в строке :position испорчена. '.$restart,
            'rows.*.discount.max' => 'Скидка в строке :position длиннее 64 символов — оставьте процент, например «20 %».',
            'rows.*.wholesale.string' => 'Оптовая цена в строке :position испорчена. '.$restart,
            'rows.*.wholesale.max' => 'Оптовая цена в строке :position длиннее 64 символов — оставьте только число.',
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
