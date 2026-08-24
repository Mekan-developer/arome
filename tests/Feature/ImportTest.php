<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\CatalogGenerator;
use App\Services\CatalogSheetLayout;
use App\Services\ExportService;
use App\Services\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_a_fresh_visit_shows_an_empty_wizard(): void
    {
        $props = $this->props($this->actingAs($this->admin())->get('/import'));

        $this->assertNull($props['fileName']);
        $this->assertNull($props['storedPath']);
        $this->assertSame([], $props['rows']);
        $this->assertSame(['total' => 0, 'warn' => 0, 'err' => 0, 'ok' => 0], $props['counters']);
        $this->assertSame([], $props['recent']);
    }

    public function test_analyzing_a_valid_row_returns_it_as_ok_and_keeps_the_upload_on_disk(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1 415,88', ''],
        ]);

        $props = $this->props($this->actingAs($this->admin())->post('/import', ['file' => $file]));

        $this->assertSame('price-list.xlsx', $props['fileName']);
        $this->assertSame(['total' => 1, 'warn' => 0, 'err' => 0, 'ok' => 1], $props['counters']);
        $this->assertSame('ok', $props['rows'][0]['type']);
        $this->assertSame('510028', $props['rows'][0]['sku']);
        $this->assertSame('1 415,88', $props['rows'][0]['retail']);
        $this->assertMatchesRegularExpression('#^imports/[A-Za-z0-9]+\.xlsx$#', $props['storedPath']);
        $this->assertTrue(Storage::exists($props['storedPath']));
    }

    public function test_a_blank_article_is_rejected(): void
    {
        $row = $this->analyzeRow(['AA1001', '', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->assertSame('err', $row['type']);
        $this->assertSame('АРТИКУЛ', $row['tag']);
        $this->assertSame('sku', $row['field']);
    }

    public function test_a_duplicate_article_in_the_file_is_flagged_on_the_second_occurrence(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1002', '510028', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
        ]);

        $props = $this->props($this->actingAs($this->admin())->post('/import', ['file' => $file]));

        $this->assertSame('ok', $props['rows'][0]['type']);
        $this->assertSame('err', $props['rows'][1]['type']);
        $this->assertSame('ДУБЛЬ', $props['rows'][1]['tag']);
    }

    public function test_a_blank_name_is_rejected(): void
    {
        $row = $this->analyzeRow(['AA1001', '510028', '8011003993802', '', '1415.88', '']);

        $this->assertSame('err', $row['type']);
        $this->assertSame('НОМЕНКЛАТУРА', $row['tag']);
        $this->assertSame('name', $row['field']);
    }

    public function test_a_barcode_that_is_not_thirteen_digits_is_rejected(): void
    {
        $row = $this->analyzeRow(['AA1001', '510028', '801100399380', 'VERSACE EROS EDT 50ML', '1780.00', '']);

        $this->assertSame('err', $row['type']);
        $this->assertSame('ШТРИХКОД', $row['tag']);
        $this->assertSame('barcode', $row['field']);
    }

    /**
     * A supplier renumbering an article keeps the barcode — the row's sku is new, so
     * this is a rename of the existing product, not a clash. {@see self::test_confirming_renames_a_product_matched_by_barcode_when_the_sku_is_new()}
     * covers what confirm() actually does with it.
     */
    public function test_a_barcode_already_used_by_a_different_article_is_a_rename_when_the_sku_is_new(): void
    {
        Product::factory()->create(['sku' => '999999', 'barcode' => '8011003993802']);

        $row = $this->analyzeRow(['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->assertSame('ok', $row['type']);
    }

    public function test_a_barcode_belonging_to_a_different_product_than_the_row_s_own_sku_is_still_rejected(): void
    {
        Product::factory()->create(['sku' => '510028', 'barcode' => '8011003990001']);
        Product::factory()->create(['sku' => '999999', 'barcode' => '8011003993802']);

        $row = $this->analyzeRow(['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->assertSame('err', $row['type']);
        $this->assertSame('ШТРИХКОД', $row['tag']);
        $this->assertStringContainsString('999999', $row['message']);
    }

    public function test_a_non_numeric_price_is_rejected(): void
    {
        $row = $this->analyzeRow(['AA1047', '512010', '8011003818877', 'D&G LIGHT BLUE EDT 100ML', '2210 манат', '']);

        $this->assertSame('err', $row['type']);
        $this->assertSame('ЦЕНА', $row['tag']);
        $this->assertSame('retail', $row['field']);
    }

    public function test_a_discount_given_as_an_amount_instead_of_a_fraction_is_rejected(): void
    {
        $row = $this->analyzeRow(['AA1024', '511044', '8011003818501', 'ARMANI ACQUA DI GIO EDT 100ML', '2340.00', '120']);

        $this->assertSame('err', $row['type']);
        $this->assertSame('СКИДКА', $row['tag']);
        $this->assertSame('discount', $row['field']);
    }

    public function test_a_blank_main_code_is_a_warning_not_an_error(): void
    {
        $row = $this->analyzeRow(['', '512044', '8011003819001', 'HUGO BOSS BOTTLED EDT 50ML', '1640.00', '']);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('НОВЫЙ', $row['tag']);
        $this->assertNull($row['field']);
    }

    /**
     * Same rename logic as the barcode case above, keyed on the main code instead.
     */
    public function test_a_main_code_already_used_by_a_different_article_is_a_rename_when_the_sku_is_new(): void
    {
        Product::factory()->create(['sku' => '999999', 'main_code' => 'AA9999']);

        $row = $this->analyzeRow(['AA9999', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->assertSame('ok', $row['type']);
    }

    public function test_a_main_code_belonging_to_a_different_product_than_the_row_s_own_sku_is_still_rejected(): void
    {
        Product::factory()->create(['sku' => '510028', 'main_code' => 'AA1001']);
        Product::factory()->create(['sku' => '999999', 'main_code' => 'AA9999']);

        $row = $this->analyzeRow(['AA9999', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->assertSame('err', $row['type']);
        $this->assertSame('ОСНОВНОЙ КОД', $row['tag']);
        $this->assertStringContainsString('999999', $row['message']);
    }

    /**
     * Barcode and main code disagree about which existing product this row would
     * rename — apply() cannot resolve two different products to one row, so this stays
     * an error even though the sku itself is new.
     */
    public function test_a_barcode_and_main_code_pointing_at_different_products_is_rejected(): void
    {
        Product::factory()->create(['sku' => '888888', 'main_code' => 'AA8888']);
        Product::factory()->create(['sku' => '999999', 'barcode' => '8011003993802']);

        $row = $this->analyzeRow(['AA8888', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->assertSame('err', $row['type']);
    }

    public function test_analyze_runs_a_bounded_number_of_queries_regardless_of_row_count(): void
    {
        $rows = [];

        for ($i = 0; $i < 40; $i++) {
            $rows[] = [
                "AA{$i}",
                (string) (600000 + $i),
                CatalogGenerator::ean13((string) (700000000000 + $i)),
                "PRODUCT {$i}",
                '100.00',
                '',
            ];
        }

        $file = $this->workbook($rows);

        DB::enableQueryLog();
        $this->actingAs($this->admin())->post('/import', ['file' => $file])->assertOk();

        $this->assertLessThan(10, count(DB::getQueryLog()));
    }

    public function test_confirming_creates_new_products_and_skips_rows_still_in_error(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1002', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', '0,5'],
            ['AA1003', '', '8011003993826', 'VERSACE BRIGHT CRYSTAL EDT 90ML', '2426.04', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $response = $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ]);

        $response->assertRedirect('/import');

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['sku' => '510028', 'name' => 'VERSACE BRIGHT CRYSTAL EDT 30ML']);
        $created = Product::where('sku', '510030')->sole();
        $this->assertSame(0.5, (float) $created->discount);

        $this->assertDatabaseHas('import_batches', [
            'file_name' => 'price-list.xlsx',
            'rows_ok' => 2,
            'rows_failed' => 1,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Импорт из Excel',
            'object' => 'price-list.xlsx',
            'value_to' => '2 строк',
            'kind' => 'import',
        ]);
    }

    public function test_confirming_updates_an_existing_product_by_sku_and_leaves_status_untouched(): void
    {
        $existing = Product::factory()->hidden()->create([
            'sku' => '510028',
            'main_code' => 'AA1001',
            'barcode' => '8011003993802',
            'name' => 'OLD NAME',
            'price' => 1000,
            'discount' => 0,
        ]);

        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'NEW NAME', '1415.88', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertDatabaseCount('products', 1);
        $existing->refresh();
        $this->assertSame('NEW NAME', $existing->name);
        $this->assertSame(1415.88, (float) $existing->price);
        $this->assertSame('hidden', $existing->status->value);

        $this->assertDatabaseHas('price_histories', [
            'product_id' => $existing->id,
            'reason' => 'импорт',
        ]);
    }

    /**
     * The row's sku is new to the catalogue, but its barcode already belongs to a
     * product — that product is renamed and updated, not skipped as a clash. This is
     * the case a supplier renumbering an article without reissuing a barcode produces.
     */
    public function test_confirming_renames_a_product_matched_by_barcode_when_the_sku_is_new(): void
    {
        $existing = Product::factory()->create([
            'sku' => '999999',
            'main_code' => 'AA1001',
            'barcode' => '8011003993802',
            'name' => 'OLD NAME',
            'price' => 1000,
        ]);

        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'NEW NAME', '1415.88', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));
        $this->assertSame('ok', $props['rows'][0]['type']);

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertDatabaseCount('products', 1);
        $existing->refresh();
        $this->assertSame('510028', $existing->sku);
        $this->assertSame('NEW NAME', $existing->name);
        $this->assertSame(1415.88, (float) $existing->price);
    }

    /**
     * Same rename, this time resolved through the main code instead of the barcode.
     */
    public function test_confirming_renames_a_product_matched_by_main_code_when_the_sku_is_new(): void
    {
        $existing = Product::factory()->create([
            'sku' => '999999',
            'main_code' => 'AA9999',
            'barcode' => '8011003993802',
            'name' => 'OLD NAME',
        ]);

        $file = $this->workbook([
            ['AA9999', '510028', '8011003993802', 'NEW NAME', '1415.88', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertDatabaseCount('products', 1);
        $existing->refresh();
        $this->assertSame('510028', $existing->sku);
        $this->assertSame('NEW NAME', $existing->name);
    }

    /**
     * Колонка H прайса — оптовая цена. Пустая ячейка не обнуляет опт карточки: прайсы
     * поставщиков сверстаны по старым семи колонкам, и такой файл не должен стирать то,
     * что администратор проставил руками.
     */
    public function test_confirming_writes_the_wholesale_price_and_a_blank_column_keeps_it(): void
    {
        $existing = Product::factory()->create([
            'sku' => '510028',
            'main_code' => 'AA1001',
            'barcode' => '8011003993802',
            'wholesale_price' => 700,
        ]);

        $admin = $this->admin();

        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '', '', '920,50'],
        ]);

        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->assertSame('920,50', $props['rows'][0]['wholesale']);

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertSame('920.50', $existing->refresh()->wholesale_price);

        $blank = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
        ]);

        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $blank]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertSame('920.50', $existing->refresh()->wholesale_price);
    }

    public function test_a_non_numeric_wholesale_price_is_rejected(): void
    {
        $row = $this->analyzeRow([
            'AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '', '', '920 манат',
        ]);

        $this->assertSame('err', $row['type']);
        $this->assertSame('ОПТ', $row['tag']);
        $this->assertSame('wholesale', $row['field']);
    }

    public function test_confirming_assigns_a_main_code_when_the_file_leaves_it_blank(): void
    {
        $file = $this->workbook([
            ['', '512044', '8011003819001', 'HUGO BOSS BOTTLED EDT 50ML', '1640.00', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $product = Product::where('sku', '512044')->sole();
        $this->assertMatchesRegularExpression('/^AA\d+$/', $product->main_code);
    }

    /**
     * apply() re-validates from scratch — a client that lies about a row's own verdict
     * must not be able to smuggle a broken row into the catalogue.
     */
    public function test_confirm_never_trusts_a_client_supplied_verdict(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => 'tampered.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [[
                'row' => 4,
                'mainCode' => 'AA1001',
                'sku' => '',
                'barcode' => '8011003993802',
                'name' => 'VERSACE BRIGHT CRYSTAL EDT 30ML',
                'retail' => '1415.88',
                'discount' => '',
                'type' => 'ok',
            ]],
        ]);

        $response->assertRedirect('/import');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseHas('import_batches', ['rows_ok' => 0, 'rows_failed' => 1]);
    }

    /**
     * What the Vue wizard actually sends after "Исправить": the same row, one field
     * overwritten in place — this is the whole point of the browser fix-up step.
     */
    public function test_a_browser_correction_that_resolves_the_problem_is_imported(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => 'fixed.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [[
                'row' => 4,
                'mainCode' => 'AA1016',
                'sku' => '510092',
                'barcode' => '8011003993911',
                'name' => 'VERSACE EROS EDT 50ML',
                'retail' => '1780.00',
                'discount' => '',
            ]],
        ]);

        $response->assertRedirect('/import');
        $this->assertDatabaseHas('products', ['sku' => '510092', 'barcode' => '8011003993911']);
    }

    public function test_confirming_deletes_the_stored_upload(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->assertTrue(Storage::exists($props['storedPath']));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ]);

        $this->assertFalse(Storage::exists($props['storedPath']));
    }

    public function test_confirming_with_a_missing_stored_file_fails_validation(): void
    {
        $response = $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'ghost.xlsx',
            'storedPath' => 'imports/'.str_repeat('a', 40).'.xlsx',
            'rows' => [],
        ]);

        $response->assertSessionHasErrors('storedPath');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_path_outside_the_imports_directory_is_rejected(): void
    {
        $response = $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'ghost.xlsx',
            'storedPath' => '../../.env',
            'rows' => [],
        ]);

        $response->assertSessionHasErrors('storedPath');
    }

    public function test_a_seller_cannot_analyze_an_import(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
        ]);

        $this->actingAs(User::factory()->create())->post('/import', ['file' => $file])->assertForbidden();
    }

    public function test_a_seller_cannot_confirm_an_import(): void
    {
        $this->actingAs(User::factory()->create())->post('/import/confirm', [
            'fileName' => 'x.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [],
        ])->assertForbidden();
    }

    public function test_a_guest_cannot_reach_the_import_wizard(): void
    {
        $this->get('/import')->assertRedirect('/login');
        $this->post('/import')->assertRedirect('/login');
    }

    public function test_the_template_download_carries_only_the_header_row(): void
    {
        $response = $this->actingAs($this->admin())->get('/import/template');

        $response->assertOk()->assertDownload();

        $path = $this->saveStreamedResponse($response);
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString(CatalogSheetLayout::HEADERS['A'], $sheet);
        $this->assertStringNotContainsString('<row r="4"', $sheet);
    }

    /**
     * The template ExportService builds and the parser ImportService reads must agree —
     * uploading the untouched template back should produce zero rows, never an error.
     */
    public function test_the_downloaded_template_re_uploads_without_error(): void
    {
        $templatePath = app(ExportService::class)->template();
        $this->tempFiles[] = $templatePath;

        $upload = new UploadedFile(
            $templatePath,
            'catalog-template.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $props = $this->props($this->actingAs($this->admin())->post('/import', ['file' => $upload]));

        $this->assertSame([], $props['rows']);
        $this->assertSame(0, $props['counters']['total']);
    }

    /**
     * Строка листа, слева направо и настолько далеко, насколько нужно тесту: колонки
     * после последней заданной остаются пустыми.
     *
     * @param  list<list<string>>  $rows
     */
    private function workbook(array $rows): UploadedFile
    {
        $sheet = new XlsxWriter(CatalogSheetLayout::SHEET_NAME, array_values(CatalogSheetLayout::WIDTHS));
        $sheet->skipRows(CatalogSheetLayout::HEADER_ROW - 1);
        $sheet->addRow(array_map(
            fn (string $header): array => XlsxWriter::text($header, XlsxWriter::STYLE_HEADER),
            array_values(CatalogSheetLayout::HEADERS),
        ));

        foreach ($rows as $row) {
            $sheet->addRow(array_map(fn (string $value): array => XlsxWriter::text($value), $row));
        }

        $path = sys_get_temp_dir().'/import-test-'.bin2hex(random_bytes(8)).'.xlsx';
        $sheet->saveTo($path);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'price-list.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Analyzes a single-row workbook and returns that row as the server validated it.
     *
     * @param  list<string>  $row
     * @return array<string, mixed>
     */
    private function analyzeRow(array $row): array
    {
        $props = $this->props($this->actingAs($this->admin())->post('/import', ['file' => $this->workbook([$row])]));

        return $props['rows'][0];
    }

    /**
     * A file on the fake disk under the hashed-name shape confirm() requires, without
     * going through a real analyze() round trip.
     */
    private function fakeStoredFile(): string
    {
        $path = 'imports/'.bin2hex(random_bytes(20)).'.xlsx';
        Storage::put($path, 'placeholder');

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $captured = null;
        $response->assertInertia(function ($page) use (&$captured): void {
            $captured = $page->toArray();
        });

        return $captured['props'];
    }

    private function saveStreamedResponse(TestResponse $response): string
    {
        $path = sys_get_temp_dir().'/import-test-download-'.bin2hex(random_bytes(8)).'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $this->tempFiles[] = $path;

        return $path;
    }
}
