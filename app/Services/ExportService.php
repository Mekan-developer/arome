<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Repositories\ProductRepository;
use RuntimeException;

/**
 * Выгрузка каталога — обратная сторона {@see ImportService}. Лист повторяет прайс,
 * который панель принимает на импорт, лист в лист: «Лист1», колонки A–H, шапка в
 * третьей строке, данные с четвёртой. Выгруженный файл правят в Excel и загружают
 * обратно, не переставляя колонки руками.
 */
class ExportService
{
    /**
     * Высота строки шапки в пунктах: «Цена со скидкой» переносится в две строки.
     */
    private const HEADER_HEIGHT = 30.0;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null}  $filters
     */
    public function fileName(array $filters): string
    {
        $status = (string) ($filters['status'] ?? 'all');
        $suffix = in_array($status, ProductStatus::values(), true) ? '-'.$status : '';

        return 'catalog'.$suffix.'-'.now()->format('Y-m-d-Hi').'.xlsx';
    }

    /**
     * Журнальная запись делается до отдачи файла. Прайс уезжает из панели наружу —
     * это то действие, которое должно оставлять след вместе с числом строк.
     *
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null}  $filters
     * @return int число строк, попавших в выгрузку
     */
    public function record(array $filters, bool $withPoints, string $actor): int
    {
        $count = $this->products->countMatching($filters, $withPoints);

        $this->audit->record(
            $actor,
            'Выгрузка каталога',
            'Строк: '.$count,
            null,
            $this->describeFilters($filters),
            'product',
        );

        return $count;
    }

    /**
     * Собирает книгу во временный файл и отдаёт путь к ней. Товары читаются курсором,
     * лист пишется на диск построчно — размер каталога на память не влияет.
     *
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null}  $filters
     * @return string путь к собранному .xlsx; удалить его — забота вызывающего
     */
    public function write(array $filters, bool $withPoints): string
    {
        $sheet = $this->emptySheet();

        foreach ($this->products->stream($filters, $withPoints) as $product) {
            $sheet->addRow($this->row($product));
        }

        return $this->save($sheet, 'aroma-catalog-');
    }

    /**
     * Blank workbook in the exact shape import expects — the "Скачать шаблон .xlsx"
     * card on step 1 of the wizard. Same headers, same row 3, zero data rows.
     *
     * @return string путь к собранному .xlsx; удалить его — забота вызывающего
     */
    public function template(): string
    {
        return $this->save($this->emptySheet(), 'aroma-template-');
    }

    /**
     * A sheet with nothing but the header row in place — the starting point for both a
     * real export and the blank template.
     */
    private function emptySheet(): XlsxWriter
    {
        $sheet = new XlsxWriter(CatalogSheetLayout::SHEET_NAME, array_values(CatalogSheetLayout::WIDTHS));

        $sheet->skipRows(CatalogSheetLayout::HEADER_ROW - 1);
        $sheet->addRow(
            array_map(
                fn (string $header): array => XlsxWriter::text($header, XlsxWriter::STYLE_HEADER),
                array_values(CatalogSheetLayout::HEADERS),
            ),
            self::HEADER_HEIGHT,
        );

        return $sheet;
    }

    private function save(XlsxWriter $sheet, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Не удалось создать временный файл Excel.');
        }

        $sheet->saveTo($path);

        return $path;
    }

    /**
     * Строка прайса. Пустая скидка оставляет пустыми и её колонку, и цену со скидкой —
     * ровно так же, как в исходном файле: пустая ячейка читается как «скидки нет»,
     * а ноль пришлось бы глазами отличать от настоящей нулевой скидки.
     *
     * Товар, которому цену со скидкой назвал сам прайс, выгружается тем же способом,
     * каким пришёл: колонка «Скидки» пуста, в «Цене со скидкой» стоит его цена. Так
     * выгруженный файл, загруженный обратно, повторяет каталог, а не пересчитывает его.
     *
     * @return list<array{kind: string, value: string, style: int}>
     */
    private function row(Product $product): array
    {
        $price = (float) $product->price;
        $discount = (float) $product->discount;
        $discounted = $discount > 0 || $product->discount_price !== null;

        return [
            XlsxWriter::text($product->main_code),
            XlsxWriter::text($product->sku),
            XlsxWriter::text($product->barcode),
            XlsxWriter::text($product->name),
            XlsxWriter::number($price, XlsxWriter::STYLE_MONEY_BOLD),
            $discount > 0 ? XlsxWriter::number($discount) : XlsxWriter::blank(),
            $discounted
                ? XlsxWriter::number($product->finalPrice(), XlsxWriter::STYLE_MONEY)
                : XlsxWriter::blank(),
            /* Опта у товара может не быть — пустая ячейка, а не ноль: ноль это цена. */
            $product->wholesale_price === null
                ? XlsxWriter::blank()
                : XlsxWriter::number((float) $product->wholesale_price, XlsxWriter::STYLE_MONEY),
        ];
    }

    /**
     * Человекочитаемый след фильтров для журнала.
     *
     * @param  array{q?: string|null, point?: string|null, status?: string|null, sort?: string|null}  $filters
     */
    private function describeFilters(array $filters): string
    {
        $parts = [];

        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $parts[] = 'поиск «'.trim((string) $filters['q']).'»';
        }

        $status = ProductStatus::tryFrom((string) ($filters['status'] ?? 'all'));

        if ($status instanceof ProductStatus) {
            $parts[] = 'статус: '.mb_strtolower($status->label());
        }

        $point = (string) ($filters['point'] ?? 'all');

        if ($point !== 'all' && $point !== '') {
            $parts[] = 'точка №'.(int) $point;
        }

        return $parts === [] ? 'весь каталог' : implode(' · ', $parts);
    }
}
