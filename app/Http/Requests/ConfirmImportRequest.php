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
    /**
     * Replaces what used to be `rows.*.<field>` wildcard rules. Laravel's wildcard
     * validator re-flattens the entire `rows` array (Arr::dot(), via
     * ValidationData::initializeAndGatherData()) once per wildcard rule and then
     * pattern-matches every flattened key against it — fine for a handful of rows, but a
     * price list of a few thousand (exactly what this wizard is for) pushed that past
     * the 120s PHP execution limit and turned every large import into a 500. The plain
     * loop in {@see self::validateRows()} does the same checks in one pass over the rows
     * actually submitted.
     *
     * @var array<string, array{max: int, stringMessage: string, maxMessage: string}>
     */
    private const ROW_FIELDS = [
        'mainCode' => [
            'max' => 64,
            'stringMessage' => 'Основной код в строке :position испорчен. ',
            'maxMessage' => 'Основной код в строке :position длиннее 64 символов — сократите его и повторите импорт.',
        ],
        'sku' => [
            'max' => 64,
            'stringMessage' => 'Артикул в строке :position испорчен. ',
            'maxMessage' => 'Артикул в строке :position длиннее 64 символов — сократите его и повторите импорт.',
        ],
        'barcode' => [
            'max' => 64,
            'stringMessage' => 'Штрихкод в строке :position испорчен. ',
            'maxMessage' => 'Штрихкод в строке :position длиннее 64 символов — в нём должно быть 13 цифр.',
        ],
        'name' => [
            'max' => 512,
            'stringMessage' => 'Номенклатура в строке :position испорчена. ',
            'maxMessage' => 'Номенклатура в строке :position длиннее 512 символов — сократите название.',
        ],
        'retail' => [
            'max' => 64,
            'stringMessage' => 'Розничная цена в строке :position испорчена. ',
            'maxMessage' => 'Розничная цена в строке :position длиннее 64 символов — оставьте только число.',
        ],
        'discount' => [
            'max' => 64,
            'stringMessage' => 'Скидка в строке :position испорчена. ',
            'maxMessage' => 'Скидка в строке :position длиннее 64 символов — оставьте процент, например «20 %».',
        ],
        'wholesale' => [
            'max' => 64,
            'stringMessage' => 'Оптовая цена в строке :position испорчена. ',
            'maxMessage' => 'Оптовая цена в строке :position длиннее 64 символов — оставьте только число.',
        ],
    ];

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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateStoredPath($validator);
            $this->validateRows($validator);
        });
    }

    private function validateStoredPath(Validator $validator): void
    {
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
    }

    /**
     * Hand-rolled stand-in for the old `rows.*.<field>` wildcard rules — see
     * {@see self::ROW_FIELDS} for why. `:position` mirrors Laravel's own wildcard
     * placeholder: the row's 1-based position in the submitted array, not its `row`
     * (spreadsheet line) value.
     */
    private function validateRows(Validator $validator): void
    {
        if ($validator->errors()->has('rows')) {
            return;
        }

        $restart = 'Начните заново с шага «Файл»: загрузите прайс ещё раз.';

        /** @var list<mixed> $rows */
        $rows = $this->input('rows', []);

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $validator->errors()->add("rows.{$index}", "Строка прайса пришла в неизвестном виде. {$restart}");

                continue;
            }

            $position = $index + 1;

            $this->validateRowNumber($validator, $row, $index);

            foreach (self::ROW_FIELDS as $field => $rule) {
                $this->validateRowField($validator, $row, $index, $position, $field, $rule, $restart);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function validateRowNumber(Validator $validator, array $row, int $index): void
    {
        $value = $row['row'] ?? null;
        $isInteger = is_int($value) || (is_string($value) && ctype_digit($value));

        if (! $isInteger || (int) $value < 1) {
            $validator->errors()->add("rows.{$index}.row", 'В данных строки повреждена нумерация — начните заново с шага «Файл».');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{max: int, stringMessage: string, maxMessage: string}  $rule
     */
    private function validateRowField(Validator $validator, array $row, int $index, int $position, string $field, array $rule, string $restart): void
    {
        $value = $row[$field] ?? null;

        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $validator->errors()->add(
                "rows.{$index}.{$field}",
                str_replace(':position', (string) $position, $rule['stringMessage']).$restart
            );

            return;
        }

        if (mb_strlen($value) > $rule['max']) {
            $validator->errors()->add(
                "rows.{$index}.{$field}",
                str_replace(':position', (string) $position, $rule['maxMessage'])
            );
        }
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
