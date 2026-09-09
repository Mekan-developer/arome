<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Repositories\ProductRepository;
use Illuminate\Support\Facades\DB;

/**
 * The Excel import wizard. The uploaded sheet must be exactly what {@see ExportService}
 * produces — {@see CatalogSheetLayout} is the one definition both agree on.
 *
 * Прайс — это каталог целиком, а не поправка к нему: каждый импорт стирает все товары и
 * заводит их заново из файла. Ничего не сопоставляется — ни по артикулу, ни по
 * штрихкоду, ни по основному коду: строка файла всегда заводит новую карточку, а всё,
 * что стояло в каталоге до загрузки, уходит вместе с историей цен, остатками по точкам
 * и сканами (внешние ключи этих таблиц каскадные). Вернуть прежний каталог можно только
 * резервной копией, которую мастер предлагает забрать в окне подтверждения,
 * см. {@see ImportController::backup()}.
 *
 * Заказчик просил загружать прайс как есть: ни одна строка файла импорт не отклоняет.
 * Пустой может быть любая колонка, повториться — тоже любая, и такая строка всё равно
 * заводит товар. {@see self::validateRow()} не отбраковывает, а приводит ячейку к тому,
 * что колонка каталога способна хранить, и объясняет оператору предупреждением, что
 * именно с ней стало: нераспознанная цена станет нулём, скидка вне диапазона
 * подожмётся, слишком длинный текст обрежется, повторённый основной код заменится
 * свободным. Товар с пустым штрихкодом сканер в зале не найдёт, но и штрихкод за
 * поставщика никто не придумывает.
 *
 * Two passes read the same rows through the same normalisation:
 *  - {@see self::analyze()} is a dry run — it never touches the products table, only
 *    reports what step 2 of the wizard shows.
 *  - {@see self::apply()} re-reads the rows from scratch (never trusting a
 *    client-supplied verdict), стирает каталог и пишет каждую из них.
 */
class ImportService
{
    /**
     * Сколько символов держат колонки products.main_code, products.sku и
     * products.barcode. Длиннее ячейка не отклоняется, а обрезается: иначе строка
     * валила бы вставку, а с ней и весь прайс одной транзакцией.
     */
    private const LIMIT_CODE = 64;

    /**
     * То же для products.name.
     */
    private const LIMIT_NAME = 255;

    /**
     * Потолок колонок products.price и products.wholesale_price — decimal(10,2), но цены
     * при импорте всегда округляются до целого, поэтому потолок тоже целый: иначе
     * {@see self::money()} (0 знаков после запятой) округлил бы «99999999,99» до
     * «100000000» при обратном разборе в {@see self::payload()} — числа, для которого в
     * колонке уже не хватило бы разрядов.
     */
    private const MAX_PRICE = 99999999.0;

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
        $rows = $this->validateRows($this->extractRows($absolutePath));

        return [
            'fileName' => $originalName,
            'sheetNote' => CatalogSheetLayout::note(),
            'totalRows' => count($rows),
            'rows' => $rows,
            'counters' => self::counters($rows),
            /* Сколько карточек уйдёт из каталога — весь он: прайс заменяет каталог
             * целиком. Пустой файл не удаляет ничего, см. {@see self::apply()}. */
            'obsolete' => $rows !== [] ? $this->products->countAll() : 0,
        ];
    }

    /**
     * The rows come back from the browser in the exact shape {@see self::analyze()} sent
     * them in — see resources/js/Pages/Import/Index.vue. Они разбираются здесь заново, с
     * нуля: ни значения ячеек, ни вердикт 'type', с которым их последний раз видел
     * браузер, на веру не берутся.
     *
     * Импортируются все строки без исключения, поэтому 'failed' здесь всегда ноль — поле
     * остаётся в ответе и в {@see ImportBatch}, потому что история загрузок на шаге 1
     * показывает и старые импорты, у которых пропущенные строки были.
     *
     * @param  list<array<string, mixed>>  $rawRows
     * @return array{ok: int, failed: int, created: int, deleted: int}
     */
    public function apply(array $rawRows, string $fileName, string $actor): array
    {
        return DB::transaction(function () use ($rawRows, $fileName, $actor): array {
            $rows = $this->validateRows($rawRows);

            /*
             * Файл, из которого не пришло ни одной строки, каталог не трогает вовсе: это
             * не «прайс опустел», а испорченная загрузка, и стирать по ней весь каталог
             * нельзя. По той же причине здесь не двигается ревизия каталога.
             */
            $deleted = $rows !== [] ? $this->products->deleteAll() : 0;

            /*
             * Основной код остаётся уникальным, и первым на него право у строки, которая
             * принесла его в файле, — поэтому коды прайса резервируются все разом, до
             * записи. Иначе строка без кода получила бы сгенерированный AA1005, а строка
             * с «AA1005» из файла, дойди до неё очередь позже, осталась бы без своего.
             */
            $owner = self::mainCodeOwners($rows);
            $reserved = array_fill_keys(array_keys($owner), true);
            $nextMainCodeNumber = null;
            $created = 0;

            foreach ($rows as $index => $row) {
                $payload = $this->payload($row);

                /* Повторённый в файле код строке не достаётся — он уже за строкой выше,
                 * и такая строка получает следующий свободный. */
                if ($payload['mainCode'] === '' || $owner[$payload['mainCode']] !== $index) {
                    $payload['mainCode'] = $this->nextFreeMainCode($reserved, $nextMainCodeNumber);
                }

                $this->productService->createFromImport($payload);
                $created++;
            }

            ImportBatch::create([
                'file_name' => $fileName,
                'imported_at' => now(),
                'rows_ok' => $created,
                'rows_failed' => 0,
            ]);

            $this->audit->record($actor, 'Импорт из Excel', $fileName, null, $created.' строк', 'import');

            if ($deleted > 0) {
                $this->audit->record($actor, 'Каталог заменён прайсом', $fileName, $deleted.' товаров', $created.' товаров', 'import');
            }

            if ($created > 0) {
                $this->catalogVersion->bump();
            }

            return ['ok' => $created, 'failed' => 0, 'created' => $created, 'deleted' => $deleted];
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
     * Какой строке файла достаётся каждый основной код: первой, которая его принесла.
     * Позиция в массиве, а не номер строки листа, — номер приходит из браузера и
     * повториться может, позиция нет.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private static function mainCodeOwners(array $rows): array
    {
        $owner = [];

        foreach ($rows as $index => $row) {
            $code = (string) $row['mainCode'];

            if ($code !== '') {
                $owner[$code] ??= $index;
            }
        }

        return $owner;
    }

    /**
     * Основной код новому товару. Первый номер серии спрашивается у каталога один раз
     * за импорт, дальше счёт идёт в памяти: новых карточек в прайсе бывают тысячи, а
     * {@see ProductRepository::nextMainCode()} на каждую из них сортировал бы каталог
     * заново.
     *
     * Занятые коды — это и коды из самого файла, и уже выданные здесь: и те и другие
     * лежат в $reserved.
     *
     * @param  array<string, true>  $reserved
     */
    private function nextFreeMainCode(array &$reserved, ?int &$number): string
    {
        $number ??= (int) substr($this->products->nextMainCode(), 2);

        while (isset($reserved['AA'.$number])) {
            $number++;
        }

        $code = 'AA'.$number;
        $reserved[$code] = true;
        $number++;

        return $code;
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
                'final' => trim((string) ($cells[CatalogSheetLayout::COLUMN_FINAL] ?? '')),
                'wholesale' => trim((string) ($cells[CatalogSheetLayout::COLUMN_WHOLESALE] ?? '')),
            ];

            $blank = $raw['mainCode'] === '' && $raw['sku'] === '' && $raw['barcode'] === ''
                && $raw['name'] === '' && $raw['retail'] === '' && $raw['discount'] === ''
                && $raw['final'] === '' && $raw['wholesale'] === '';

            if ($blank) {
                continue;
            }

            $rows[] = $raw;
        }

        return $rows;
    }

    /**
     * Каждая строка файла, приведённая к тому, что примет каталог. С базой этот проход
     * не разговаривает вовсе: каталога к моменту записи уже не будет, поэтому повтор
     * ищется только внутри самого файла.
     *
     * @param  list<array<string, mixed>>  $rawRows
     * @return list<array<string, mixed>>
     */
    private function validateRows(array $rawRows): array
    {
        $seen = ['sku' => [], 'barcode' => [], 'mainCode' => []];

        /*
         * A plain loop, not array_map: validateRow() accumulates into $seen by reference
         * as it goes, to catch a sku/barcode/main_code repeated later in the same file —
         * an arrow function only ever captures $seen by value, so the accumulation would
         * silently be lost between rows.
         */
        $rows = [];

        foreach ($rawRows as $raw) {
            $rows[] = $this->validateRow($raw, $seen);
        }

        return $rows;
    }

    /**
     * One row, brought to the shape the catalogue can store. Ничего не отклоняется: у
     * строки нет исхода «ошибка», она либо проходит молча, либо проходит с
     * предупреждением о том, что с её ячейками сделали. Пустая колонка — не повод
     * отказать (пустыми могут быть все восемь), повтор — тоже: вторая такая же строка
     * заводит второй товар, а уникальность остаётся только у основного кода, и его
     * дубль получает свободный код в {@see self::apply()}.
     *
     * @param  array<string, mixed>  $raw
     * @param  array{sku: array<string,int>, barcode: array<string,string>, mainCode: array<string,string>}  $seen
     * @return array<string, mixed>
     */
    private function validateRow(array $raw, array &$seen): array
    {
        $number = (int) $raw['row'];
        ['sku' => $sku, 'barcode' => $barcodeDigits, 'mainCode' => $mainCode] = self::keys($raw);
        $name = self::clip((string) ($raw['name'] ?? ''), self::LIMIT_NAME);
        $retailRaw = trim((string) ($raw['retail'] ?? ''));
        $discountRaw = trim((string) ($raw['discount'] ?? ''));
        $finalRaw = trim((string) ($raw['final'] ?? ''));
        $wholesaleRaw = trim((string) ($raw['wholesale'] ?? ''));

        $retail = self::clampPrice(self::roundPrice(self::toFloat($retailRaw))) ?? 0.0;
        $discount = self::clampDiscount($discountRaw === '' ? 0.0 : self::toDiscount($discountRaw));
        $wholesale = self::clampPrice(self::roundPrice($wholesaleRaw === '' ? null : self::toFloat($wholesaleRaw)));

        /*
         * Скидка процентом главнее: колонка «Цена со скидкой» читается, только когда
         * «Скидки» пуста. Прайс, выгруженный самой панелью, заполняет обе, и там эти
         * две колонки говорят одно и то же — а вот в файле поставщика процента может
         * не быть вовсе, и тогда цена со скидкой остаётся единственным, что о скидке
         * известно, вычислять её не из чего. Она и станет продажной ценой карточки,
         * см. {@see ProductService::finalPrice()}.
         */
        $discountPrice = $discountRaw === '' && $finalRaw !== ''
            ? self::clampPrice(self::roundPrice(self::toFloat($finalRaw)))
            : null;

        /*
         * Кто уже занял штрихкод и основной код — только строка выше в этом же файле:
         * каталог импорт стирает целиком, спорить строке не с кем.
         *
         * Пустой артикул и пустой штрихкод ничьи: без этой оговорки вторая строка без
         * штрихкода «нашла» бы владельцем первую такую же.
         */
        $barcodeOwner = self::rivalOwner($barcodeDigits, $seen['barcode']);
        $mainCodeOwner = self::rivalOwner($mainCode, $seen['mainCode']);
        $skuSeenInRow = $sku !== '' ? ($seen['sku'][$sku] ?? null) : null;

        if ($sku !== '') {
            $seen['sku'][$sku] ??= $number;
        }

        if ($barcodeDigits !== '') {
            $seen['barcode'][$barcodeDigits] ??= $sku;
        }

        if ($mainCode !== '') {
            $seen['mainCode'][$mainCode] ??= $sku;
        }

        /*
         * Прайс сохраняется как есть: ничего за поставщика не придумывается и ничего не
         * отбрасывается. Оператор читает в предупреждении ровно то, что с его строкой
         * стало, — от «основной код присвоится сам» до «цена не распозналась».
         */
        $notices = [];
        $duplicate = false;

        if ($skuSeenInRow !== null) {
            $notices[] = "Артикул «{$sku}» уже встречался в строке {$skuSeenInRow} — строка сохранится вторым товаром.";
            $duplicate = true;
        } elseif ($sku === '') {
            $notices[] = 'Артикул пуст — товар сохранится без него.';
        }

        if ($name === '') {
            $notices[] = 'Номенклатура пуста — товар сохранится без названия, и продавец не найдёт его поиском.';
        }

        if ($mainCode === '') {
            $notices[] = 'Основной код пуст — присвоится автоматически.';
        } elseif ($mainCodeOwner !== null) {
            $notices[] = "Основной код «{$mainCode}» уже занят {$mainCodeOwner} — строке присвоится свободный.";
            $duplicate = true;
        }

        if ($barcodeDigits === '') {
            $notices[] = 'Штрихкод пуст — сканер в зале товар не найдёт.';
        } elseif ($barcodeOwner !== null) {
            $notices[] = "Штрихкод «{$barcodeDigits}» уже используется {$barcodeOwner} — сканер найдёт по нему оба товара.";
            $duplicate = true;
        }

        if ($retailRaw === '') {
            $notices[] = 'Цена пуста — товар сохранится с ценой 0.';
        } elseif ($retail <= 0) {
            $notices[] = "Цена «{$retailRaw}» не распозналась как положительное число — товар сохранится с ценой 0.";
        }

        if ($discountRaw !== '' && self::toDiscount($discountRaw) !== $discount) {
            $notices[] = "Скидка «{$discountRaw}» вне допустимого диапазона — сохранится ".self::percent($discount).'.';
        }

        if ($discountRaw === '' && $finalRaw !== '') {
            if ($discountPrice === null) {
                $notices[] = "Цена со скидкой «{$finalRaw}» не распозналась — товар будет продаваться по розничной.";
            } elseif ($discountPrice > $retail) {
                $notices[] = 'Цена со скидкой выше розничной — товар будет продаваться по ней, '.self::money($discountPrice).'.';
            } else {
                $notices[] = 'Скидка не указана процентом — товар будет продаваться по цене из колонки «Цена со скидкой», '.self::money($discountPrice).'.';
            }
        }

        if ($wholesaleRaw !== '' && $wholesale === null) {
            $notices[] = "Оптовая цена «{$wholesaleRaw}» не распозналась — товар сохранится без оптовой цены.";
        }

        return [
            'row' => $number,
            'mainCode' => $mainCode,
            'sku' => $sku,
            'barcode' => $barcodeDigits,
            'name' => $name,
            'retail' => self::money($retail),
            'discount' => $discount > 0 ? self::percent($discount) : '',
            /*
             * Пустая ячейка у строки без скидки — не украшение: этот же массив уезжает
             * в браузер и возвращается в {@see self::apply()}, где непустая «Цена со
             * скидкой» при пустом проценте и означает «цену назвал прайс». Стой здесь
             * розничная цена, каждый товар без скидки получил бы свою цену со скидкой,
             * равную розничной. Оператору пустую ячейку рисует «= розн.», см.
             * resources/js/Pages/Import/Partials/ImportIssueRow.vue.
             */
            'final' => self::finalCell($retail, $discount, $discountPrice),
            'wholesale' => $wholesale !== null ? self::money($wholesale) : '',
            'type' => $notices !== [] ? 'warn' : 'ok',
            'tag' => match (true) {
                $duplicate => 'ДУБЛЬ',
                $notices !== [] => 'НОВЫЙ',
                default => null,
            },
            /* Строку больше не нужно чинить руками, чтобы она прошла, — поле «Исправить»
             * не показывается: см. resources/js/Pages/Import/Partials/ImportIssueRow.vue. */
            'field' => null,
            'fix' => null,
            'message' => $notices !== [] ? implode(' ', $notices) : null,
        ];
    }

    /**
     * Строка прайса тем, чем её заведут в каталоге. Пустая колонка — это пустое поле
     * карточки, а не «оставить как было»: прежней карточки после {@see self::apply()}
     * уже нет, каталог целиком приходит из файла.
     *
     * @param  array<string, mixed>  $row
     * @return array{mainCode: string, sku: string, barcode: string, name: string, price: float, discount: float, discountPrice: float|null, wholesalePrice: float|null}
     */
    private function payload(array $row): array
    {
        $wholesale = trim((string) ($row['wholesale'] ?? ''));
        $discount = trim((string) ($row['discount'] ?? ''));
        $final = trim((string) ($row['final'] ?? ''));

        return [
            'mainCode' => (string) $row['mainCode'],
            'sku' => (string) $row['sku'],
            'barcode' => (string) $row['barcode'],
            'name' => (string) $row['name'],
            'price' => self::toFloat((string) $row['retail']) ?? 0.0,
            'discount' => $discount !== '' ? (self::toDiscount($discount) ?? 0.0) : 0.0,
            /* Процент главнее — см. {@see self::validateRow()}: своя цена со скидкой
             * есть только у строки, где процента не назвали. */
            'discountPrice' => $discount === '' && $final !== '' ? self::toFloat($final) : null,
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

    /**
     * Ячейка, ужатая до того, что примет колонка каталога.
     */
    private static function clip(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }

    /**
     * Кто уже держит это значение, названный так, как его прочтёт оператор
     * («артикулом «510028»»), — или null, если значение свободно. Спорить строка может
     * только со строкой выше в этом же файле: каталога к записи уже не будет.
     *
     * @param  array<string, string>  $claimedInFile  значение => артикул занявшей строки
     */
    private static function rivalOwner(string $value, array $claimedInFile): ?string
    {
        if ($value === '' || ! array_key_exists($value, $claimedInFile)) {
            return null;
        }

        return self::owner($claimedInFile[$value]);
    }

    /**
     * Три ключа строки, обрезанные по ширине колонок каталога, — то, чем строка ляжет в
     * каталог и по чему в файле ищется её повтор.
     *
     * @param  array<string, mixed>  $raw
     * @return array{sku: string, barcode: string, mainCode: string}
     */
    private static function keys(array $raw): array
    {
        return [
            'sku' => self::clip((string) ($raw['sku'] ?? ''), self::LIMIT_CODE),
            'barcode' => self::clip(preg_replace('/\D/', '', (string) ($raw['barcode'] ?? '')) ?? '', self::LIMIT_CODE),
            'mainCode' => self::clip((string) ($raw['mainCode'] ?? ''), self::LIMIT_CODE),
        ];
    }

    /**
     * Товар без артикула назвать по нему нельзя, поэтому в предупреждении он остаётся
     * безымянным — оператор всё равно узнаёт его по значению, о котором идёт речь.
     */
    private static function owner(string $sku): string
    {
        return $sku !== '' ? "артикулом «{$sku}»" : 'товаром без артикула';
    }

    /**
     * Цена в границах decimal(10,2): отрицательная — это опечатка в прайсе, а не долг
     * покупателю, и становится нулём; выходящее за потолок число подрезается вместо
     * того, чтобы уронить вставку.
     */
    private static function clampPrice(?float $value): ?float
    {
        return $value !== null ? max(0.0, min(self::MAX_PRICE, $value)) : null;
    }

    /**
     * Скидка долей от 0 до 1. Больше сотни процентов не бывает: «120» в колонке скидки
     * — это либо процент за пределом, либо сумма в манатах, и в обоих случаях товар
     * отдаётся даром, а не с доплатой. Нераспознанная скидка — это ноль, а не отказ.
     */
    private static function clampDiscount(?float $value): float
    {
        return $value !== null ? max(0.0, min(1.0, $value)) : 0.0;
    }

    private static function money(float $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    /**
     * Колонка «Цена со скидкой» на проверке строк — и она же то, из чего
     * {@see self::payload()} прочитает названную прайсом цену, когда строка вернётся из
     * браузера. Поэтому у товара без скидки ячейка пуста: непустой она значит «скидка
     * у строки есть», а не «столько стоит».
     */
    private static function finalCell(float $retail, float $discount, ?float $discountPrice): string
    {
        if ($discountPrice !== null) {
            return self::money($discountPrice);
        }

        return $discount > 0 ? self::money(self::roundPrice($retail * (1 - $discount)) ?? 0.0) : '';
    }

    /**
     * Скидка в карточке хранится долей (0,5), а оператор читает и пишет её процентом —
     * колонка прайса принимает обе записи. «20 %» — процент явно; «20» без знака —
     * тоже процент (доли больше единицы не бывает, а 20 в этой колонке всегда значило
     * «двадцать процентов»); «0,2» — доля, как её кладёт в файл {@see ExportService}.
     * Сумма скидки в манатах остаётся ошибкой: 120 — это 120 %, больше единицы, и
     * {@see self::clampDiscount()} подожмёт её до ста.
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
