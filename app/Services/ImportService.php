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
 * normal case this covers, see {@see self::validateRow()}. A blank barcode does not block
 * the row and is not filled in for the supplier: the product is saved without one.
 *
 * Two passes read the same rows through the same validation:
 *  - {@see self::analyze()} is a dry run — it never touches the products table, only
 *    reports what step 3 of the wizard shows.
 *  - {@see self::apply()} re-validates (never trusting a client-supplied verdict) and
 *    writes everything that passes; rows still in error are skipped, not imported.
 *
 * Файл задаёт каталог целиком: товар, которого в прайсе не оказалось, удаляется вместе
 * с историей цен, остатками и сканами. Оператора предупреждает окно подтверждения —
 * число обречённых карточек считает {@see self::countObsolete()} ещё на разборе файла,
 * и оттуда же предлагается забрать резервную копию каталога.
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
     * @return array{fileName: string, sheetNote: string, totalRows: int, rows: list<array<string, mixed>>, counters: array{total: int, ok: int, warn: int, err: int}, obsolete: int}
     */
    public function analyze(string $absolutePath, string $originalName): array
    {
        [$rows, $bySku, $byBarcode, $byMainCode] = $this->validateRows($this->extractRows($absolutePath));

        return [
            'fileName' => $originalName,
            'sheetNote' => CatalogSheetLayout::note(),
            'totalRows' => count($rows),
            'rows' => $rows,
            'counters' => self::counters($rows),
            'obsolete' => $this->countObsolete($rows, $bySku, $byBarcode, $byMainCode),
        ];
    }

    /**
     * The rows come back from the browser in the exact shape {@see self::analyze()} sent
     * them in, with whatever the operator corrected in "Исправить" already merged in —
     * see resources/js/Pages/Import/Index.vue. They are re-validated here from scratch;
     * a row's 'type' as the browser last saw it is never trusted.
     *
     * @param  list<array<string, mixed>>  $rawRows
     * @return array{ok: int, failed: int, created: int, updated: int, deleted: int}
     */
    public function apply(array $rawRows, string $fileName, string $actor): array
    {
        return DB::transaction(function () use ($rawRows, $fileName, $actor): array {
            [$rows, $bySku, $byBarcode, $byMainCode] = $this->validateRows($rawRows);

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $kept = [];
            $claimedMainCodes = [];
            $nextMainCodeNumber = null;

            foreach ($rows as $row) {
                if ($row['type'] === 'err') {
                    $skipped++;

                    continue;
                }

                /*
                 * Карточку занимает первая же строка, которая на неё легла: повтор
                 * артикула в прайсе — это второй товар, а не спор за один и тот же, и
                 * перезаписывать им первую строку нельзя.
                 */
                $existing = $this->existingFor($row, $bySku, $byBarcode, $byMainCode);

                if ($existing instanceof Product && isset($kept[$existing->id])) {
                    $existing = null;
                }

                $payload = $this->payload($row);

                /*
                 * Основной код — код самой панели, и уникальным он остаётся. Если тот,
                 * что стоит в строке, уже занят другой карточкой, строка его не отбирает:
                 * новый товар получает следующий свободный, а обновляемый остаётся при
                 * своём. Иначе повторный импорт того же прайса с дублями падал бы на
                 * уникальном индексе.
                 */
                $owner = $payload['mainCode'] !== '' ? $byMainCode->get($payload['mainCode']) : null;
                $takenByOther = isset($claimedMainCodes[$payload['mainCode']])
                    || ($owner instanceof Product && ($existing === null || $owner->id !== $existing->id));

                if ($payload['mainCode'] !== '' && $takenByOther) {
                    $payload['mainCode'] = '';
                }

                if ($payload['mainCode'] === '' && $existing === null) {
                    $payload['mainCode'] = $this->nextFreeMainCode($claimedMainCodes, $nextMainCodeNumber);
                }

                $product = $this->productService->upsertFromImport($payload, $existing, $actor);

                $kept[$product->id] = true;
                $claimedMainCodes[$product->main_code] = true;
                $product->wasRecentlyCreated ? $created++ : $updated++;
            }

            $applied = $created + $updated;

            /*
             * Прайс — это каталог целиком: товар, которого в файле не оказалось, из базы
             * уходит вместе с историей цен, остатками и сканами. Строки с ошибками свой
             * товар не защищают — не прошла строка, значит товара в прайсе нет.
             *
             * Файл, из которого не прошла ни одна строка, каталог не меняет вовсе: это
             * не «прайс опустел», а испорченная загрузка, и стирать по ней весь каталог
             * нельзя. По той же причине здесь не двигается ревизия каталога.
             */
            $deleted = $applied > 0 ? $this->products->deleteExcept(array_keys($kept)) : 0;

            ImportBatch::create([
                'file_name' => $fileName,
                'imported_at' => now(),
                'rows_ok' => $applied,
                'rows_failed' => $skipped,
            ]);

            $this->audit->record($actor, 'Импорт из Excel', $fileName, null, $applied.' строк', 'import');

            if ($deleted > 0) {
                $this->audit->record($actor, 'Удалены товары вне прайса', $fileName, null, $deleted.' товаров', 'import');
            }

            if ($applied > 0) {
                $this->catalogVersion->bump();
            }

            return ['ok' => $applied, 'failed' => $skipped, 'created' => $created, 'updated' => $updated, 'deleted' => $deleted];
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
     * Основной код новому товару. Первый номер серии спрашивается у каталога один раз
     * за импорт, дальше счёт идёт в памяти: новых карточек в прайсе бывают тысячи, а
     * {@see ProductRepository::nextMainCode()} на каждую из них сортировал бы каталог
     * заново.
     *
     * Номер, который в этом же файле уже кому-то достался, пропускается: строка со
     * своим кодом из прайса тоже занимает его через $claimed.
     *
     * @param  array<string, true>  $claimed
     */
    private function nextFreeMainCode(array $claimed, ?int &$number): string
    {
        $number ??= (int) substr($this->products->nextMainCode(), 2);

        while (isset($claimed['AA'.$number])) {
            $number++;
        }

        $code = 'AA'.$number;
        $number++;

        return $code;
    }

    /**
     * Какой карточке принадлежит строка. Sku первым — им же судил
     * {@see self::validateRow()}. Когда артикул новый, а штрихкод или основной код уже
     * за кем-то числятся, это перенумерованный поставщиком товар, а не новый: строка
     * обновляет и переименовывает его, а не спорит с ним.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, Product>  $bySku
     * @param  Collection<string, Product>  $byBarcode
     * @param  Collection<string, Product>  $byMainCode
     */
    private function existingFor(array $row, Collection $bySku, Collection $byBarcode, Collection $byMainCode): ?Product
    {
        return ($row['sku'] !== '' ? $bySku->get($row['sku']) : null)
            ?? ($row['barcode'] !== '' ? $byBarcode->get($row['barcode']) : null)
            ?? ($row['mainCode'] !== '' ? $byMainCode->get($row['mainCode']) : null);
    }

    /**
     * Сколько товаров каталога в файле не встретилось — их удалит {@see self::apply()},
     * и это число мастер показывает в окне подтверждения до нажатия «Импортировать».
     * Считается по тем же правилам, по которым потом идёт удаление: карточку сохраняет
     * только строка, которая дойдёт до импорта.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<string, Product>  $bySku
     * @param  Collection<string, Product>  $byBarcode
     * @param  Collection<string, Product>  $byMainCode
     */
    private function countObsolete(array $rows, Collection $bySku, Collection $byBarcode, Collection $byMainCode): int
    {
        $kept = [];
        $applicable = 0;

        foreach ($rows as $row) {
            if ($row['type'] === 'err') {
                continue;
            }

            $applicable++;
            $existing = $this->existingFor($row, $bySku, $byBarcode, $byMainCode);

            if ($existing instanceof Product) {
                $kept[$existing->id] = true;
            }
        }

        /* Ни одной прошедшей строки — импорт ничего не удалит, см. apply(). */
        if ($applicable === 0) {
            return 0;
        }

        return max(0, $this->products->countAll() - count($kept));
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

        $barcodes = array_values(array_filter(
            array_map(fn (array $raw): string => preg_replace('/\D/', '', (string) ($raw['barcode'] ?? '')) ?? '', $rawRows),
            fn (string $barcode): bool => $barcode !== '',
        ));

        $skus = array_values(array_filter(
            array_map(fn (array $raw): string => trim((string) ($raw['sku'] ?? '')), $rawRows),
            fn (string $sku): bool => $sku !== '',
        ));

        $existing = $this->products->matchingImportKeys($skus, $barcodes, $mainCodes);

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
        $retail = self::roundPrice(self::toFloat($retailRaw));
        $discount = $discountRaw === '' ? 0.0 : self::toDiscount($discountRaw);
        $wholesale = self::roundPrice($wholesaleRaw === '' ? null : self::toFloat($wholesaleRaw));

        /*
         * The prior claim on a barcode/main_code, split by source: a file-owner is an
         * earlier row in this same import, a db-owner is a product already in the
         * database. Null means unclaimed; equal to this row's own sku means it is this
         * row's own product, not a clash.
         */
        /* Пустой артикул и пустой штрихкод ничьи: у товаров без них keyBy() кладёт всех
         * под один пустой ключ, и без этих проверок пустая строка спорила бы за него с
         * чужой карточкой. */
        $skuExists = $sku !== '' && $bySku->has($sku);
        $fileBarcodeOwner = $barcodeDigits !== '' ? ($seen['barcode'][$barcodeDigits] ?? null) : null;
        $dbBarcodeOwner = $barcodeDigits !== '' ? $byBarcode->get($barcodeDigits)?->sku : null;
        $barcodeOwner = $fileBarcodeOwner ?? $dbBarcodeOwner;
        $fileMainCodeOwner = $mainCode !== '' ? ($seen['mainCode'][$mainCode] ?? null) : null;
        $dbMainCodeOwner = $mainCode !== '' ? $byMainCode->get($mainCode)?->sku : null;
        $mainCodeOwner = $fileMainCodeOwner ?? $dbMainCodeOwner;
        $skuSeenInRow = $sku !== '' ? ($seen['sku'][$sku] ?? null) : null;

        $issue = null;

        if ($name === '') {
            $issue = ['tag' => 'НОМЕНКЛАТУРА', 'field' => 'name', 'fix' => 'название', 'message' => 'Пустая номенклатура. Название обязательно — продавец ищет товар по нему.'];
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
            $issue = ['tag' => 'СКИДКА', 'field' => 'discount', 'fix' => '20 %', 'message' => 'Скидка задана некорректно. В колонке «Скидки» ожидается процент: например 20 % или 0,2 — не сумма скидки и не больше 100 %.'];
        } elseif ($wholesaleRaw !== '' && ($wholesale === null || $wholesale < 0)) {
            $issue = ['tag' => 'ОПТ', 'field' => 'wholesale', 'fix' => 'число', 'message' => 'Оптовая цена нечисловая или отрицательная. Колонка «Оптовая цена» числовая; оставьте её пустой, если опта у товара нет.'];
        } elseif (
            $mainCode !== '' && $mainCodeOwner !== null && $mainCodeOwner !== $sku
            && ($fileMainCodeOwner !== null || $skuExists || ($barcodeOwner !== null && $barcodeOwner !== $mainCodeOwner))
        ) {
            $issue = ['tag' => 'ОСНОВНОЙ КОД', 'field' => 'mainCode', 'fix' => 'AA####', 'message' => "Основной код «{$mainCode}» уже используется товаром с артикулом «{$mainCodeOwner}»."];
        }

        if ($sku !== '') {
            $seen['sku'][$sku] ??= $number;
        }

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
                'discount' => $discount !== null && $discountRaw !== '' ? self::percent($discount) : $discountRaw,
                'final' => '',
                'wholesale' => $wholesale !== null ? self::money($wholesale) : $wholesaleRaw,
                'type' => 'err',
                ...$issue,
            ];
        }

        /*
         * Ни пустой код, ни повтор артикула строку не отклоняют — прайс сохраняется как
         * есть, ничего за поставщика не придумывается и ничего не отбрасывается. Оператор
         * видит это предупреждением: основной код присвоится сам, товар без штрихкода не
         * найдёт сканер, а повторившийся артикул заведёт вторую карточку.
         */
        $notices = [];

        if ($skuSeenInRow !== null) {
            $notices[] = "Артикул «{$sku}» уже встречался в строке {$skuSeenInRow} — будет создан второй товар.";
        } elseif ($sku === '') {
            $notices[] = 'Артикул пуст — товар сохранится без него.';
        }

        if ($mainCode === '') {
            $notices[] = 'Основной код пуст — присвоится автоматически.';
        }

        if ($barcodeDigits === '') {
            $notices[] = 'Штрихкод пуст — сканер в зале товар не найдёт.';
        }

        return [
            'row' => $number,
            'mainCode' => $mainCode,
            'sku' => $sku,
            'barcode' => $barcodeDigits,
            'name' => $name,
            'retail' => self::money($retail),
            'discount' => $discount > 0 ? self::percent($discount) : '',
            'final' => self::money(ProductService::finalPrice($retail, $discount)),
            'wholesale' => $wholesale !== null ? self::money($wholesale) : '',
            'type' => $notices !== [] ? 'warn' : 'ok',
            'tag' => match (true) {
                $skuSeenInRow !== null => 'ДУБЛЬ',
                $notices !== [] => 'НОВЫЙ',
                default => null,
            },
            'field' => null,
            'fix' => null,
            'message' => $notices !== [] ? implode(' ', $notices) : null,
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
            'discount' => $row['discount'] !== '' ? (self::toDiscount((string) $row['discount']) ?? 0.0) : 0.0,
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

    /**
     * Заказчик просил при импорте округлять цены до целого числа математически: от
     * 0,5 и выше — вверх, ниже — вниз (round() по умолчанию считает именно так, half
     * away from zero, а цены здесь всегда неотрицательны).
     */
    private static function roundPrice(?float $value): ?float
    {
        return $value !== null ? round($value) : null;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    /**
     * Скидка в карточке хранится долей (0,5), а оператор читает и пишет её процентом —
     * колонка прайса принимает обе записи. «20 %» — процент явно; «20» без знака —
     * тоже процент (доли больше единицы не бывает, а 20 в этой колонке всегда значило
     * «двадцать процентов»); «0,2» — доля, как её кладёт в файл {@see ExportService}.
     * Сумма скидки в манатах остаётся ошибкой: 120 — это 120 %, больше единицы, и
     * {@see self::validateRow()} такую строку отклоняет.
     */
    private static function toDiscount(string $value): ?float
    {
        $trimmed = trim($value);
        $isPercent = str_ends_with($trimmed, '%');
        $number = self::toFloat($isPercent ? substr($trimmed, 0, -1) : $trimmed);

        if ($number === null) {
            return null;
        }

        return $isPercent || $number > 1 ? $number / 100 : $number;
    }

    /**
     * Обратная сторона {@see self::toDiscount()}: доля 0,5 показывается оператору как
     * «50 %» — тем же процентом, что и в карточке товара. Пробел неразрывный, чтобы
     * знак не отрывался от числа в узкой колонке.
     */
    private static function percent(float $value): string
    {
        return str_replace('.', ',', (string) round($value * 100, 2))."\u{00A0}%";
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
