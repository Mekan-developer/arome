<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\Product;
use App\Repositories\ProductRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Excel import wizard. The uploaded sheet must be exactly what {@see ExportService}
 * produces — {@see CatalogSheetLayout} is the one definition both agree on — and rows
 * are matched on identity (sku, then barcode, then main code), never on the row's
 * position in the file. Sku is tried first; when a row's sku is new but its barcode or
 * main code already belongs to a product, that product is renamed rather than treated
 * as a clash — a supplier renumbering an article without reissuing a new barcode is the
 * normal case this covers, see {@see self::validateRow()}.
 *
 * Two passes read the same rows through the same validation:
 *  - {@see self::analyze()} is a dry run — it never touches the products table, only
 *    reports what step 3 of the wizard shows.
 *  - {@see self::apply()} re-validates (never trusting a client-supplied verdict) and
 *    writes everything that passes; rows still in error are skipped, not imported.
 */
class ImportService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductService $productService,
        private readonly AuditService $audit,
        private readonly CatalogVersionService $catalogVersion,
    ) {}

    /**
     * @return array{fileName: string, sheetNote: string, totalRows: int, rows: list<array<string, mixed>>, counters: array{total: int, ok: int, warn: int, err: int}}
     */
    public function analyze(string $absolutePath, string $originalName): array
    {
        [$rows] = $this->validateRows($this->extractRows($absolutePath));

        return [
            'fileName' => $originalName,
            'sheetNote' => CatalogSheetLayout::note(),
            'totalRows' => count($rows),
            'rows' => $rows,
            'counters' => self::counters($rows),
        ];
    }

    /**
     * The rows come back from the browser in the exact shape {@see self::analyze()} sent
     * them in, with whatever the operator corrected in "Исправить" already merged in —
     * see resources/js/Pages/Import/Index.vue. They are re-validated here from scratch;
     * a row's 'type' as the browser last saw it is never trusted.
     *
     * @param  list<array<string, mixed>>  $rawRows
     * @return array{ok: int, failed: int, created: int, updated: int}
     */
    public function apply(array $rawRows, string $fileName, string $actor): array
    {
        return DB::transaction(function () use ($rawRows, $fileName, $actor): array {
            [$rows, $bySku, $byBarcode, $byMainCode] = $this->validateRows($rawRows);

            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                if ($row['type'] === 'err') {
                    $skipped++;

                    continue;
                }

                /*
                 * Sku first, same as validateRow() judged it by. When the sku is new but
                 * the barcode or main code already belongs to a product, that is a
                 * renumbered article, not a new one — the row updates (and renames) it
                 * instead of colliding with it.
                 */
                $existing = $bySku->get($row['sku'])
                    ?? $byBarcode->get($row['barcode'])
                    ?? ($row['mainCode'] !== '' ? $byMainCode->get($row['mainCode']) : null);

                $action = $this->productService->upsertFromImport(
                    $this->payload($row),
                    $existing,
                    $actor,
                );

                $action === 'created' ? $created++ : $updated++;
            }

            $applied = $created + $updated;

            ImportBatch::create([
                'file_name' => $fileName,
                'imported_at' => now(),
                'rows_ok' => $applied,
                'rows_failed' => $skipped,
            ]);

            $this->audit->record($actor, 'Импорт из Excel', $fileName, null, $applied.' строк', 'import');

            /* Файл, из которого не прошла ни одна строка, каталог не менял. */
            if ($applied > 0) {
                $this->catalogVersion->bump();
            }

            return ['ok' => $applied, 'failed' => $skipped, 'created' => $created, 'updated' => $updated];
        });
    }

    /**
     * History card on step 1.
     *
     * @return list<array{date: string, file: string, ok: int, failed: int}>
     */
    public function recent(): array
    {
        return ImportBatch::orderByDesc('imported_at')
            ->limit(3)
            ->get()
            ->map(fn (ImportBatch $batch): array => [
                'date' => $batch->imported_at->format('d.m.Y'),
                'file' => $batch->file_name,
                'ok' => $batch->rows_ok,
                'failed' => $batch->rows_failed,
            ])
            ->all();
    }

    /**
     * Reads the sheet from {@see CatalogSheetLayout::FIRST_DATA_ROW} on, trims every
     * field and drops fully blank rows — a trailing empty row is not a 4 000th product.
     *
     * @return list<array<string, string|int>>
     */
    private function extractRows(string $absolutePath): array
    {
        $rows = [];

        foreach ((new XlsxReader)->rows($absolutePath) as $number => $cells) {
            if ($number < CatalogSheetLayout::FIRST_DATA_ROW) {
                continue;
            }

            $raw = [
                'row' => $number,
                'mainCode' => trim((string) ($cells[CatalogSheetLayout::COLUMN_MAIN_CODE] ?? '')),
                'sku' => trim((string) ($cells[CatalogSheetLayout::COLUMN_SKU] ?? '')),
                'barcode' => trim((string) ($cells[CatalogSheetLayout::COLUMN_BARCODE] ?? '')),
                'name' => trim((string) ($cells[CatalogSheetLayout::COLUMN_NAME] ?? '')),
                'retail' => trim((string) ($cells[CatalogSheetLayout::COLUMN_RETAIL] ?? '')),
                'discount' => trim((string) ($cells[CatalogSheetLayout::COLUMN_DISCOUNT] ?? '')),
                'wholesale' => trim((string) ($cells[CatalogSheetLayout::COLUMN_WHOLESALE] ?? '')),
            ];

            $blank = $raw['mainCode'] === '' && $raw['sku'] === '' && $raw['barcode'] === ''
                && $raw['name'] === '' && $raw['retail'] === '' && $raw['discount'] === ''
                && $raw['wholesale'] === '';

            if ($blank) {
                continue;
            }

            $rows[] = $raw;
        }

        return $rows;
    }

    /**
     * Validates every row against the catalogue in one batched lookup — a price list of
     * a few thousand rows must not cost three queries per row just to check uniqueness.
     * Returns the sku => Product map alongside the rows so {@see self::apply()} does not
     * have to run the same lookup a second time to decide create vs. update.
     *
     * @param  list<array<string, mixed>>  $rawRows
     * @return array{0: list<array<string, mixed>>, 1: Collection<string, Product>, 2: Collection<string, Product>, 3: Collection<string, Product>}
     */
    private function validateRows(array $rawRows): array
    {
        $mainCodes = array_values(array_filter(
            array_map(fn (array $raw): string => trim((string) ($raw['mainCode'] ?? '')), $rawRows),
            fn (string $code): bool => $code !== '',
        ));

        $existing = $this->products->matchingImportKeys(
            array_map(fn (array $raw): string => trim((string) ($raw['sku'] ?? '')), $rawRows),
            array_map(fn (array $raw): string => preg_replace('/\D/', '', (string) ($raw['barcode'] ?? '')) ?? '', $rawRows),
            $mainCodes,
        );

        $bySku = $existing->keyBy('sku');
        $byBarcode = $existing->keyBy('barcode');
        $byMainCode = $existing->keyBy('main_code');

        $seen = ['sku' => [], 'barcode' => [], 'mainCode' => []];

        /*
         * A plain loop, not array_map: validateRow() accumulates into $seen by reference
         * as it goes, to catch a sku/barcode/main_code repeated later in the same file —
         * an arrow function only ever captures $seen by value, so the accumulation would
         * silently be lost between rows.
         */
        $rows = [];

        foreach ($rawRows as $raw) {
            $rows[] = $this->validateRow($raw, $bySku, $byBarcode, $byMainCode, $seen);
        }

        return [$rows, $bySku, $byBarcode, $byMainCode];
    }

    /**
     * One row, checked in the order an operator would want to fix things: identity
     * first (sku is the match key — falling back to barcode, then main code, when the
     * sku is new; see the barcode/main-code checks below), then the fields that block a
     * database write, then the soft "will be created" notice. Only the first problem
     * found is reported — the row goes back to "Исправить", not a wall of every check
     * that failed.
     *
     * @param  array<string, mixed>  $raw
     * @param  Collection<string, Product>  $bySku
     * @param  Collection<string, Product>  $byBarcode
     * @param  Collection<string, Product>  $byMainCode
     * @param  array{sku: array<string,int>, barcode: array<string,string>, mainCode: array<string,string>}  $seen
     * @return array<string, mixed>
     */
    private function validateRow(array $raw, Collection $bySku, Collection $byBarcode, Collection $byMainCode, array &$seen): array
    {
        $number = (int) $raw['row'];
        $sku = trim((string) ($raw['sku'] ?? ''));
        $name = trim((string) ($raw['name'] ?? ''));
        $mainCode = trim((string) ($raw['mainCode'] ?? ''));
        $barcodeRaw = trim((string) ($raw['barcode'] ?? ''));
        $barcodeDigits = preg_replace('/\D/', '', $barcodeRaw) ?? '';
        $retailRaw = trim((string) ($raw['retail'] ?? ''));
        $discountRaw = trim((string) ($raw['discount'] ?? ''));
        $wholesaleRaw = trim((string) ($raw['wholesale'] ?? ''));
        $retail = self::toFloat($retailRaw);
        $discount = $discountRaw === '' ? 0.0 : self::toFloat($discountRaw);
        $wholesale = $wholesaleRaw === '' ? null : self::toFloat($wholesaleRaw);

        /*
         * The prior claim on a barcode/main_code, split by source: a file-owner is an
         * earlier row in this same import, a db-owner is a product already in the
         * database. Null means unclaimed; equal to this row's own sku means it is this
         * row's own product, not a clash.
         */
        $skuExists = $bySku->has($sku);
        $fileBarcodeOwner = $seen['barcode'][$barcodeDigits] ?? null;
        $dbBarcodeOwner = $byBarcode->get($barcodeDigits)?->sku;
        $barcodeOwner = $fileBarcodeOwner ?? $dbBarcodeOwner;
        $fileMainCodeOwner = $seen['mainCode'][$mainCode] ?? null;
        $dbMainCodeOwner = $byMainCode->get($mainCode)?->sku;
        $mainCodeOwner = $fileMainCodeOwner ?? $dbMainCodeOwner;

        $issue = null;

        if ($sku === '') {
            $issue = ['tag' => 'АРТИКУЛ', 'field' => 'sku', 'fix' => '510xxx', 'message' => 'Пустой артикул. Строка не сопоставляется с каталогом — артикул это ключ.'];
        } elseif (isset($seen['sku'][$sku])) {
            $issue = ['tag' => 'ДУБЛЬ', 'field' => 'retail', 'fix' => 'цена', 'message' => "Артикул «{$sku}» уже встречался в строке {$seen['sku'][$sku]}. Какая цена верная?"];
        } elseif ($name === '') {
            $issue = ['tag' => 'НОМЕНКЛАТУРА', 'field' => 'name', 'fix' => 'название', 'message' => 'Пустая номенклатура. Название обязательно — продавец ищет товар по нему.'];
        } elseif (strlen($barcodeDigits) !== 13) {
            $issue = ['tag' => 'ШТРИХКОД', 'field' => 'barcode', 'fix' => '13 цифр', 'message' => sprintf('В штрихкоде %d %s вместо 13. Проверьте, не потерялась ли цифра.', strlen($barcodeDigits), self::digitsWord(strlen($barcodeDigits)))];
        } elseif (
            $barcodeOwner !== null && $barcodeOwner !== $sku
            && ($fileBarcodeOwner !== null || $skuExists || ($mainCodeOwner !== null && $mainCodeOwner !== $barcodeOwner))
        ) {
            /*
             * A db-owned barcode on a brand-new sku is not a clash — it is the same
             * product re-numbered by the supplier, and apply() renames it. It only stays
             * an error when the sku already belongs to someone else, the barcode was
             * already claimed earlier in this same file, or the main code disagrees
             * about which product this row renames.
             */
            $issue = ['tag' => 'ШТРИХКОД', 'field' => 'barcode', 'fix' => 'новый штрихкод', 'message' => "Штрихкод «{$barcodeDigits}» уже используется товаром с артикулом «{$barcodeOwner}»."];
        } elseif ($retail === null || $retail <= 0) {
            $issue = ['tag' => 'ЦЕНА', 'field' => 'retail', 'fix' => 'число', 'message' => 'Цена нечисловая или не больше нуля. Уберите лишние символы — колонка числовая, валюта всегда TMT.'];
        } elseif ($discount === null || $discount < 0 || $discount >= 1) {
            $issue = ['tag' => 'СКИДКА', 'field' => 'discount', 'fix' => '0,2', 'message' => 'Скидка задана некорректно. В колонке «Скидки» ожидается доля от 0 до 1: например 0,2 или 20 % — не сумма и не больше единицы.'];
        } elseif ($wholesaleRaw !== '' && ($wholesale === null || $wholesale < 0)) {
            $issue = ['tag' => 'ОПТ', 'field' => 'wholesale', 'fix' => 'число', 'message' => 'Оптовая цена нечисловая или отрицательная. Колонка «Оптовая цена» числовая; оставьте её пустой, если опта у товара нет.'];
        } elseif (
            $mainCode !== '' && $mainCodeOwner !== null && $mainCodeOwner !== $sku
            && ($fileMainCodeOwner !== null || $skuExists || ($barcodeOwner !== null && $barcodeOwner !== $mainCodeOwner))
        ) {
            $issue = ['tag' => 'ОСНОВНОЙ КОД', 'field' => 'mainCode', 'fix' => 'AA####', 'message' => "Основной код «{$mainCode}» уже используется товаром с артикулом «{$mainCodeOwner}»."];
        }

        $seen['sku'][$sku] = $number;

        if ($barcodeDigits !== '') {
            $seen['barcode'][$barcodeDigits] ??= $sku;
        }

        if ($mainCode !== '') {
            $seen['mainCode'][$mainCode] ??= $sku;
        }

        if ($issue !== null) {
            return [
                'row' => $number,
                'mainCode' => $mainCode,
                'sku' => $sku,
                'barcode' => $barcodeDigits !== '' ? $barcodeDigits : $barcodeRaw,
                'name' => $name,
                'retail' => $retail !== null ? self::money($retail) : $retailRaw,
                'discount' => $discount !== null && $discountRaw !== '' ? self::fraction($discount) : $discountRaw,
                'final' => '',
                'wholesale' => $wholesale !== null ? self::money($wholesale) : $wholesaleRaw,
                'type' => 'err',
                ...$issue,
            ];
        }

        if ($mainCode === '') {
            return [
                'row' => $number,
                'mainCode' => '',
                'sku' => $sku,
                'barcode' => $barcodeDigits,
                'name' => $name,
                'retail' => self::money($retail),
                'discount' => $discount > 0 ? self::fraction($discount) : '',
                'final' => self::money(ProductService::finalPrice($retail, $discount)),
                'wholesale' => $wholesale !== null ? self::money($wholesale) : '',
                'type' => 'warn',
                'tag' => 'НОВЫЙ',
                'field' => null,
                'fix' => null,
                'message' => 'Основной код пуст — товар будет создан, код присвоится автоматически.',
            ];
        }

        return [
            'row' => $number,
            'mainCode' => $mainCode,
            'sku' => $sku,
            'barcode' => $barcodeDigits,
            'name' => $name,
            'retail' => self::money($retail),
            'discount' => $discount > 0 ? self::fraction($discount) : '',
            'final' => self::money(ProductService::finalPrice($retail, $discount)),
            'wholesale' => $wholesale !== null ? self::money($wholesale) : '',
            'type' => 'ok',
            'tag' => null,
            'field' => null,
            'fix' => null,
            'message' => null,
        ];
    }

    /**
     * Пустая колонка «Оптовая цена» — это не ноль и не «стереть»: опт у такой строки
     * остаётся тем, что уже стоит в карточке. Иначе прайс поставщика, свёрстанный по
     * старым семи колонкам, обнулял бы опт всему каталогу разом.
     *
     * @param  array<string, mixed>  $row
     * @return array{mainCode: string, sku: string, barcode: string, name: string, price: float, discount: float, wholesalePrice: float|null}
     */
    private function payload(array $row): array
    {
        $wholesale = trim((string) ($row['wholesale'] ?? ''));

        return [
            'mainCode' => (string) $row['mainCode'],
            'sku' => (string) $row['sku'],
            'barcode' => (string) $row['barcode'],
            'name' => (string) $row['name'],
            'price' => self::toFloat((string) $row['retail']) ?? 0.0,
            'discount' => $row['discount'] !== '' ? (self::toFloat((string) $row['discount']) ?? 0.0) : 0.0,
            'wholesalePrice' => $wholesale !== '' ? self::toFloat($wholesale) : null,
        ];
    }

    /**
     * Accepts both what a human types ("1 415,88", "0,5") and a plain machine value
     * ("1415.88") — a corrected cell in the browser and a cell read from Excel go
     * through the exact same parser.
     */
    private static function toFloat(string $value): ?float
    {
        $normalized = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        return $normalized !== '' && is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    private static function fraction(float $value): string
    {
        return str_replace('.', ',', (string) round($value, 4));
    }

    private static function digitsWord(int $count): string
    {
        return $count === 1 ? 'цифра' : 'цифры';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{total: int, ok: int, warn: int, err: int}
     */
    private static function counters(array $rows): array
    {
        $warn = count(array_filter($rows, fn (array $row): bool => $row['type'] === 'warn'));
        $err = count(array_filter($rows, fn (array $row): bool => $row['type'] === 'err'));

        return [
            'total' => count($rows),
            'warn' => $warn,
            'err' => $err,
            'ok' => count($rows) - $warn - $err,
        ];
    }
}
