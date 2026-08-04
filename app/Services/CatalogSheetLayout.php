<?php

namespace App\Services;

/**
 * The one fact {@see ExportService} and {@see ImportService} both answer to: a price
 * list is always the same seven columns in the same position. Export writes this shape
 * and import only ever reads this shape back — the two must never drift apart, so the
 * layout lives here once instead of twice.
 */
final class CatalogSheetLayout
{
    public const SHEET_NAME = 'Лист1';

    /**
     * Rows 1–2 are blank, exactly like the price list the panel was built against.
     */
    public const HEADER_ROW = 3;

    public const FIRST_DATA_ROW = 4;

    public const COLUMN_MAIN_CODE = 'A';

    public const COLUMN_SKU = 'B';

    public const COLUMN_BARCODE = 'C';

    public const COLUMN_NAME = 'D';

    public const COLUMN_RETAIL = 'E';

    public const COLUMN_DISCOUNT = 'F';

    public const COLUMN_FINAL = 'G';

    /**
     * Column letter => header text, in sheet order.
     *
     * @var array<string, string>
     */
    public const HEADERS = [
        self::COLUMN_MAIN_CODE => 'Основной код',
        self::COLUMN_SKU => 'Артикул',
        self::COLUMN_BARCODE => 'Штрихкод',
        self::COLUMN_NAME => 'Номенклатура',
        self::COLUMN_RETAIL => 'Розничная цена',
        self::COLUMN_DISCOUNT => 'Скидки',
        self::COLUMN_FINAL => 'Цена со скидкой',
    ];

    /**
     * Column letter => width in characters, for {@see XlsxWriter}.
     *
     * @var array<string, float>
     */
    public const WIDTHS = [
        self::COLUMN_MAIN_CODE => 16,
        self::COLUMN_SKU => 16,
        self::COLUMN_BARCODE => 20,
        self::COLUMN_NAME => 46,
        self::COLUMN_RETAIL => 16,
        self::COLUMN_DISCOUNT => 10,
        self::COLUMN_FINAL => 12,
    ];

    /**
     * Sheet note shown on step 1 of the import wizard and in the export banner.
     */
    public static function note(): string
    {
        return sprintf(
            'лист «%s» · колонки A–G · шапка в строке %d · данные с %d-й',
            self::SHEET_NAME,
            self::HEADER_ROW,
            self::FIRST_DATA_ROW,
        );
    }
}
