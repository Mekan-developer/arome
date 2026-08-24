<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\CatalogSheetLayout;
use App\Services\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class ProductExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    public function test_it_downloads_an_xlsx_workbook(): void
    {
        Product::factory()->count(3)->create();

        $response = $this->actingAs($this->admin())->get('/products/export');

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload();

        $this->assertMatchesRegularExpression(
            '/filename="?catalog-\d{4}-\d{2}-\d{2}-\d{4}\.xlsx"?/',
            (string) $response->headers->get('content-disposition'),
        );
    }

    /**
     * Excel считает книгу повреждённой, если нет любой из обязательных частей пакета.
     */
    public function test_the_workbook_carries_every_part_excel_requires(): void
    {
        Product::factory()->create();

        $zip = $this->workbook($this->actingAs($this->admin())->get('/products/export'));

        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/worksheets/sheet1.xml',
        ] as $part) {
            $this->assertNotFalse($zip->locateName($part), "В книге нет части {$part}.");
        }

        $this->assertStringContainsString('name="Лист1"', (string) $zip->getFromName('xl/workbook.xml'));
        $zip->close();
    }

    /**
     * Строки 1–2 пустые, шапка в 3-й, данные с 4-й — та же раскладка, что у прайса,
     * который читает мастер импорта.
     */
    public function test_the_header_sits_in_the_third_row_and_data_starts_in_the_fourth(): void
    {
        Product::factory()->create(['name' => 'VERSACE BRIGHT CRYSTAL EDT 30ML']);

        $sheet = $this->sheet($this->actingAs($this->admin())->get('/products/export'));

        $this->assertStringNotContainsString('<row r="1"', $sheet);
        $this->assertStringNotContainsString('<row r="2"', $sheet);

        foreach (array_values(CatalogSheetLayout::HEADERS) as $index => $header) {
            $reference = XlsxWriter::columnName($index).'3';

            $this->assertStringContainsString(
                '<c r="'.$reference.'" s="'.XlsxWriter::STYLE_HEADER.'" t="inlineStr"><is><t xml:space="preserve">'.$header.'</t></is></c>',
                $sheet,
                "Колонка {$reference} должна нести шапку «{$header}» со стилем шапки.",
            );
        }

        $this->assertStringContainsString('<row r="4">', $sheet);
        $this->assertStringContainsString('VERSACE BRIGHT CRYSTAL EDT 30ML', $sheet);
    }

    /**
     * Артикул и штрихкод — текстовые ячейки: числом Excel покажет 8,0110E+12.
     * Цена — настоящее число, иначе по колонке не посчитать сумму.
     */
    public function test_a_row_keeps_codes_as_text_and_the_price_as_a_number(): void
    {
        Product::factory()->create([
            'main_code' => 'AA1001',
            'sku' => '510028',
            'barcode' => '8011003993802',
            'name' => 'VERSACE BRIGHT CRYSTAL EDT 30ML',
            'price' => 1415.88,
            'discount' => 0,
        ]);

        $sheet = $this->sheet($this->actingAs($this->admin())->get('/products/export'));

        $this->assertStringContainsString('<c r="A4" t="inlineStr"><is><t xml:space="preserve">AA1001</t></is></c>', $sheet);
        $this->assertStringContainsString('<c r="B4" t="inlineStr"><is><t xml:space="preserve">510028</t></is></c>', $sheet);
        $this->assertStringContainsString('<c r="C4" t="inlineStr"><is><t xml:space="preserve">8011003993802</t></is></c>', $sheet);
        $this->assertStringContainsString('<c r="E4" s="'.XlsxWriter::STYLE_MONEY_BOLD.'"><v>1415.88</v></c>', $sheet);

        /* Скидки нет — колонки F и G остаются пустыми, как в исходном прайсе. */
        $this->assertStringContainsString('<c r="F4"/>', $sheet);
        $this->assertStringContainsString('<c r="G4"/>', $sheet);
    }

    public function test_a_discount_fills_the_last_two_columns(): void
    {
        Product::factory()->discounted(0.5)->create(['price' => 1515.24]);

        $sheet = $this->sheet($this->actingAs($this->admin())->get('/products/export'));

        $this->assertStringContainsString('<c r="F4"><v>0.50</v></c>', $sheet);
        $this->assertStringContainsString('<c r="G4" s="'.XlsxWriter::STYLE_MONEY.'"><v>757.62</v></c>', $sheet);
    }

    /**
     * Оптовая цена — колонка H, восьмая и последняя. Товар без опта оставляет ячейку
     * пустой: ноль в этой колонке значил бы «отдаём даром».
     */
    public function test_the_wholesale_price_lands_in_the_last_column(): void
    {
        Product::factory()->create(['wholesale_price' => 920.5]);

        $sheet = $this->sheet($this->actingAs($this->admin())->get('/products/export'));

        $this->assertSame('Оптовая цена', CatalogSheetLayout::HEADERS[CatalogSheetLayout::COLUMN_WHOLESALE]);
        $this->assertStringContainsString('<c r="H4" s="'.XlsxWriter::STYLE_MONEY.'"><v>920.50</v></c>', $sheet);
    }

    public function test_a_product_without_a_wholesale_price_leaves_the_column_empty(): void
    {
        Product::factory()->create(['wholesale_price' => null]);

        $sheet = $this->sheet($this->actingAs($this->admin())->get('/products/export'));

        $this->assertStringContainsString('<c r="H4"/>', $sheet);
    }

    /**
     * Кавычки и амперсанды в номенклатуре ломают XML листа, если их не экранировать.
     */
    public function test_it_escapes_the_markup_characters_of_a_name(): void
    {
        Product::factory()->create(['name' => 'D&G LIGHT BLUE <"POUR HOMME"> 100ML']);

        $sheet = $this->sheet($this->actingAs($this->admin())->get('/products/export'));

        $this->assertStringContainsString('D&amp;G LIGHT BLUE &lt;&quot;POUR HOMME&quot;&gt; 100ML', $sheet);
    }

    public function test_it_exports_the_filtered_list_and_not_the_whole_catalogue(): void
    {
        Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML']);
        Product::factory()->hidden()->create(['name' => 'HUGO BOSS BOTTLED EDT 50ML']);
        Product::factory()->create(['name' => 'ARMANI SI PASSIONE EDP 50ML']);

        $sheet = $this->sheet($this->actingAs($this->admin())->get('/products/export?status=hidden'));

        $this->assertStringContainsString('HUGO BOSS BOTTLED EDT 50ML', $sheet);
        $this->assertStringNotContainsString('LATTAFA KHAMRAH EDP 100ML', $sheet);
        $this->assertSame(1, substr_count($sheet, '<row r="4">'));
        $this->assertStringNotContainsString('<row r="5">', $sheet);
    }

    public function test_the_search_filter_reaches_the_file(): void
    {
        Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML']);
        Product::factory()->create(['name' => 'HUGO BOSS BOTTLED EDT 50ML']);

        $sheet = $this->sheet($this->actingAs($this->admin())->get('/products/export?q=KHAMRAH'));

        $this->assertStringContainsString('LATTAFA KHAMRAH EDP 100ML', $sheet);
        $this->assertStringNotContainsString('HUGO BOSS BOTTLED EDT 50ML', $sheet);
    }

    /**
     * Сортировка колонки переносится в файл: выгружают то, что видно на экране.
     */
    public function test_it_keeps_the_sort_of_the_table(): void
    {
        Product::factory()->create(['name' => 'B ONE EDT 50ML', 'price' => 100]);
        Product::factory()->create(['name' => 'A TWO EDT 50ML', 'price' => 900]);

        $sheet = $this->sheet($this->actingAs($this->admin())->get('/products/export?sort=-price'));

        $this->assertLessThan(
            mb_strpos($sheet, 'B ONE EDT 50ML'),
            mb_strpos($sheet, 'A TWO EDT 50ML'),
            'Дорогой товар должен идти первым при сортировке по убыванию цены.',
        );
    }

    public function test_it_writes_a_journal_entry(): void
    {
        Product::factory()->count(2)->create();
        Product::factory()->hidden()->create();

        $this->actingAs($this->admin())->get('/products/export?status=active')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Выгрузка каталога',
            'object' => 'Строк: 2',
            'value_to' => 'статус: в продаже',
            'kind' => 'product',
        ]);
    }

    public function test_a_guest_cannot_download_the_catalogue(): void
    {
        Product::factory()->create();

        $this->get('/products/export')->assertRedirect('/login');
    }

    /**
     * Скачанная книга, открытая как zip. Вызывающий закрывает архив сам.
     */
    private function workbook(TestResponse $response): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'aroma-test-');
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive;

        $this->assertTrue($zip->open($path) === true, 'Выгрузка должна открываться как zip-пакет.');

        return $zip;
    }

    /**
     * XML единственного листа книги.
     */
    private function sheet(TestResponse $response): string
    {
        $zip = $this->workbook($response);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $sheet;
    }
}
