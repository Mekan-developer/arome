<?php

namespace Tests\Feature;

use App\Models\Point;
use App\Models\Product;
use App\Models\User;
use App\Repositories\ProductRepository;
use App\Services\CatalogGenerator;
use App\Services\CatalogSheetLayout;
use App\Services\ExportService;
use App\Services\ImportService;
use App\Services\XlsxWriter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame('1 416', $props['rows'][0]['retail']);
        $this->assertMatchesRegularExpression('#^imports/[A-Za-z0-9]+\.xlsx$#', $props['storedPath']);
        $this->assertTrue(Storage::exists($props['storedPath']));
    }

    /**
     * Артикул бывает пустым и в прайсе поставщика. Строка не отклоняется: товар
     * сохраняется без артикула, а сопоставляется по штрихкоду или основному коду.
     */
    public function test_a_blank_article_is_a_warning_not_an_error(): void
    {
        $row = $this->analyzeRow(['AA1001', '', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->assertSame('warn', $row['type']);
        $this->assertStringContainsString('Артикул пуст', $row['message']);
    }

    public function test_a_blank_article_saves_the_product_without_one(): void
    {
        $row = $this->analyzeRow(['AA1001', '', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $admin = $this->admin();
        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertNull(Product::where('barcode', '8011003993802')->sole()->sku);
    }

    /**
     * Повторившийся в файле артикул — это второй товар, а не спор двух строк за одну
     * карточку: обе сохраняются, вторая получает свой основной код.
     */
    public function test_a_duplicate_article_in_the_file_creates_a_second_product(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1002', '510028', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->assertSame('ok', $props['rows'][0]['type']);
        $this->assertSame('warn', $props['rows'][1]['type']);
        $this->assertSame('ДУБЛЬ', $props['rows'][1]['tag']);

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertSame(2, Product::where('sku', '510028')->count());
        $this->assertSame(2, Product::where('sku', '510028')->distinct()->count('main_code'));
    }

    /**
     * Тот же прайс, загруженный второй раз, не должен падать: строка легко попадает на
     * соседнюю карточку дубля, и основной код у неё уже занят. Забирать чужой код она
     * не имеет права — уникальным он остаётся.
     */
    public function test_re_importing_a_file_with_duplicates_does_not_collide_on_the_main_code(): void
    {
        $rows = [
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1002', '510028', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
        ];

        $admin = $this->admin();

        foreach (range(1, 2) as $ignored) {
            $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $this->workbook($rows)]));

            $this->actingAs($admin)->post('/import/confirm', [
                'fileName' => $props['fileName'],
                'storedPath' => $props['storedPath'],
                'rows' => $props['rows'],
            ])->assertRedirect('/import');
        }

        $this->assertSame(2, Product::where('sku', '510028')->count());
        $this->assertSame(2, Product::distinct()->count('main_code'));
    }

    /**
     * Строка, повторённая в прайсе целиком, тоже заводит вторую карточку — штрихкод у
     * них общий, уникальным он больше не считается.
     */
    public function test_a_fully_duplicated_row_creates_a_second_product(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertSame(2, Product::where('barcode', '8011003993802')->count());
    }

    /**
     * В колонке основного кода прайс приносит что угодно — «AA0000001» из чужой
     * выгрузки серию AA#### не задаёт. Следующий код считался прямо от такого
     * значения: серия откатывалась к «AA2», и второй новый товар того же файла падал
     * на уникальном индексе основного кода.
     */
    public function test_a_main_code_outside_the_series_does_not_break_the_generated_ones(): void
    {
        $file = $this->workbook([
            ['AA0000001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
            ['', '510032', '8011003993826', 'VERSACE EROS EDT 50ML', '1780.00', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertDatabaseCount('products', 3);
        $this->assertSame(3, Product::distinct()->count('main_code'));
    }

    /**
     * Тот же прайс с испорченной серией, загруженный второй раз: коды, выданные первым
     * импортом, никуда не делись, и новые не должны на них наезжать.
     */
    public function test_re_importing_a_file_with_a_main_code_outside_the_series_does_not_collide(): void
    {
        $rows = [
            ['AA0000001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
            ['', '510032', '8011003993826', 'VERSACE EROS EDT 50ML', '1780.00', ''],
        ];

        $admin = $this->admin();

        foreach (range(1, 2) as $ignored) {
            $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $this->workbook($rows)]));

            $this->actingAs($admin)->post('/import/confirm', [
                'fileName' => $props['fileName'],
                'storedPath' => $props['storedPath'],
                'rows' => $props['rows'],
            ])->assertRedirect('/import');
        }

        $this->assertDatabaseCount('products', 3);
        $this->assertSame(3, Product::distinct()->count('main_code'));
    }

    /**
     * Код, пришедший из прайса мимо серии, в счёте не участвует: «AA0000009» — это не
     * девятый номер, и следующим кодом должен быть «AA1002», а не «AA10».
     */
    public function test_the_generated_main_code_ignores_codes_outside_the_series(): void
    {
        Product::factory()->create(['sku' => '999999', 'main_code' => 'AA1001']);
        Product::factory()->create(['sku' => '999998', 'main_code' => 'AA0000009']);

        $this->assertSame('AA1002', app(ProductRepository::class)->nextMainCode());
    }

    /**
     * Отказ базы оператор читает по-русски: импорт идёт одной транзакцией, каталог от
     * падения не меняется, а файл остаётся на диске — «Импортировать» можно нажать
     * ещё раз. SQLSTATE и текст запроса уходят в лог, а не в уведомление.
     */
    public function test_a_database_failure_is_reported_in_russian_and_changes_nothing(): void
    {
        Product::factory()->create(['sku' => '999999', 'main_code' => 'AA1001']);

        $failure = new QueryException(
            'pgsql',
            'insert into "products" ("main_code") values (?)',
            ['AA5060994135481'],
            new PDOException(
                'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint '
                .'"products_main_code_unique" DETAIL: Key (main_code)=(AA5060994135481) already exists.'
            ),
        );

        $this->mock(ImportService::class)
            ->shouldReceive('apply')
            ->once()
            ->andThrow($failure);

        $storedPath = $this->fakeStoredFile();

        $response = $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $storedPath,
            'rows' => [],
        ]);

        $response->assertSessionHasErrors('rows');

        $message = (string) session('errors')->first('rows');
        $this->assertStringContainsString('основной код «AA5060994135481»', $message);
        $this->assertStringContainsString('Каталог остался прежним', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertTrue(Storage::exists($storedPath));
    }

    /**
     * Прайс грузится как есть: пустая номенклатура строку не отклоняет, товар заводится
     * без названия — оператору об этом говорят предупреждением.
     */
    public function test_a_blank_name_is_a_warning_not_an_error(): void
    {
        $row = $this->analyzeRow(['AA1001', '510028', '8011003993802', '', '1415.88', '']);

        $this->assertSame('warn', $row['type']);
        $this->assertStringContainsString('Номенклатура пуста', $row['message']);
    }

    public function test_confirming_a_blank_name_saves_the_product_without_one(): void
    {
        $row = $this->analyzeRow(['AA1001', '510028', '8011003993802', '', '1415.88', '']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertSame('', Product::where('sku', '510028')->sole()->name);
    }

    /**
     * Ни одна колонка не обязательна: строка, в которой заполнена только цена, всё равно
     * заводит товар — основной код ей присвоится сам. Совсем пустую строку
     * {@see ImportService} по-прежнему пропускает: хвост листа — это не тысячный товар.
     */
    public function test_a_row_with_every_column_blank_but_the_price_is_still_imported(): void
    {
        $row = $this->analyzeRow(['', '', '', '', '1415.88', '']);

        $this->assertSame('warn', $row['type']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $product = Product::sole();

        $this->assertNull($product->sku);
        $this->assertNull($product->barcode);
        $this->assertSame('', $product->name);
        $this->assertSame('1416.00', $product->price);
        $this->assertMatchesRegularExpression('/^AA\d+$/', $product->main_code);
    }

    /**
     * Хвост листа отбрасывается как и раньше: совсем пустая строка — не товар.
     */
    public function test_a_completely_blank_row_is_not_a_product(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['', '', '', '', '', ''],
        ]);

        $props = $this->props($this->actingAs($this->admin())->post('/import', ['file' => $file]));

        $this->assertSame(1, $props['counters']['total']);
    }

    /**
     * Значение длиннее колонки каталога прайс не роняет: оно обрезается по ширине, и
     * товар всё равно заводится. Иначе одна такая строка отбивала бы вставку, а с ней и
     * весь файл одной транзакцией.
     */
    public function test_values_longer_than_the_column_are_clipped_rather_than_rejected(): void
    {
        $row = $this->analyzeRow([
            str_repeat('A', 80), str_repeat('7', 80), '8011003993802', str_repeat('Ц', 300), '1415.88', '',
        ]);

        $this->assertSame(64, mb_strlen($row['mainCode']));
        $this->assertSame(64, mb_strlen($row['sku']));
        $this->assertSame(255, mb_strlen($row['name']));

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertSame(255, mb_strlen(Product::sole()->name));
    }

    /**
     * Not every supplier code is a valid EAN-13 — the import saves whatever barcode the
     * file has rather than rejecting the row over its length.
     */
    public function test_a_barcode_that_is_not_thirteen_digits_is_accepted_as_is(): void
    {
        $row = $this->analyzeRow(['AA1001', '510028', '801100399380', 'VERSACE EROS EDT 50ML', '1780.00', '']);

        $this->assertSame('ok', $row['type']);
        $this->assertSame('801100399380', $row['barcode']);
    }

    /**
     * Каталог до импорта на разбор строки не влияет вовсе: он будет стёрт целиком, и
     * спорить за штрихкод строке не с кем. Предупреждение остаётся только на повтор
     * внутри самого файла, {@see self::test_a_barcode_repeated_in_the_file_creates_a_second_product()}.
     */
    public function test_a_barcode_already_used_in_the_catalogue_is_not_a_warning(): void
    {
        Product::factory()->create(['sku' => '510028', 'barcode' => '8011003990001']);
        Product::factory()->create(['sku' => '999999', 'barcode' => '8011003993802']);

        $row = $this->analyzeRow(['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->assertSame('ok', $row['type']);
        $this->assertNull($row['message']);
    }

    /**
     * Один и тот же штрихкод дважды в файле — это две карточки, а не спор двух строк.
     */
    public function test_a_barcode_repeated_in_the_file_creates_a_second_product(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1002', '510030', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
        ]);

        $props = $this->props($this->actingAs($this->admin())->post('/import', ['file' => $file]));

        $this->assertSame(['total' => 2, 'warn' => 1, 'err' => 0, 'ok' => 1], $props['counters']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertSame(2, Product::where('barcode', '8011003993802')->count());
    }

    /**
     * Нечисловая цена строку не отклоняет: товар заводится с нулём, а оператор читает,
     * что именно не разобралось.
     */
    public function test_a_non_numeric_price_is_saved_as_zero(): void
    {
        $row = $this->analyzeRow(['AA1047', '512010', '8011003818877', 'D&G LIGHT BLUE EDT 100ML', '2210 манат', '']);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('0', $row['retail']);
        $this->assertStringContainsString('2210 манат', $row['message']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertSame('0.00', Product::where('sku', '512010')->sole()->price);
    }

    /**
     * Заказчик просил математическое округление цен при импорте: дробная часть от 0,5
     * и выше уходит вверх, ниже — вниз.
     */
    public function test_retail_and_wholesale_prices_are_rounded_to_the_nearest_whole_number(): void
    {
        $row = $this->analyzeRow([
            'AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1780.51', '', '', '920.49',
        ]);

        $this->assertSame('ok', $row['type']);
        $this->assertSame('1 781', $row['retail']);
        $this->assertSame('920', $row['wholesale']);
    }

    public function test_a_retail_price_that_rounds_down_to_zero_is_a_warning(): void
    {
        $row = $this->analyzeRow(['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '0.4', '']);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('0', $row['retail']);
    }

    /**
     * Отрицательная цена — опечатка в прайсе, а не долг покупателю: она подрезается до
     * нуля, но строку не отклоняет.
     */
    public function test_a_negative_price_is_clamped_to_zero(): void
    {
        $row = $this->analyzeRow(['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '-500', '']);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('0', $row['retail']);
    }

    /**
     * «120» в колонке скидки — либо процент за пределом, либо сумма в манатах. Строку
     * это не отклоняет: скидка подрезается к 100 %, иначе цена со скидкой ушла бы в
     * минус.
     */
    public function test_a_discount_beyond_a_hundred_percent_is_clamped(): void
    {
        $row = $this->analyzeRow(['AA1024', '511044', '8011003818501', 'ARMANI ACQUA DI GIO EDT 100ML', '2340.00', '120']);

        $this->assertSame('warn', $row['type']);
        $this->assertSame("100\u{00A0}%", $row['discount']);
        $this->assertSame('0', $row['final']);
        $this->assertStringContainsString('вне допустимого диапазона', $row['message']);
    }

    /**
     * Заказчик читает скидку процентом, а не долей: в файле 0,5 — на проверке «50 %».
     */
    public function test_a_discount_stored_as_a_fraction_is_shown_as_a_percent(): void
    {
        $row = $this->analyzeRow(['AA1002', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', '0,5']);

        $this->assertSame('ok', $row['type']);
        $this->assertSame("50\u{00A0}%", $row['discount']);
        $this->assertSame('961', $row['final']);
    }

    /**
     * Прайс поставщика умеет назвать цену со скидкой, не объявляя процента: колонка
     * «Скидки» пуста, а в «Цене со скидкой» стоит число. Вычислять процент не из чего —
     * эта цена и становится тем, что продавец назовёт покупателю.
     */
    public function test_a_discount_price_without_a_percent_becomes_the_selling_price(): void
    {
        $row = $this->analyzeRow([
            'AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '130', '', '120', '',
        ]);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('', $row['discount']);
        $this->assertSame('120', $row['final']);
        $this->assertStringContainsString('не указана процентом', $row['message']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $product = Product::where('sku', '510028')->sole();

        $this->assertSame('120.00', $product->discount_price);
        $this->assertSame('0.0000', $product->discount);
        $this->assertSame(120.0, $product->finalPrice());
    }

    /**
     * Обе колонки заполнены — так выгружает сама панель. Процент главнее: цена со
     * скидкой в карточку не попадает, продажную цену по-прежнему даёт процент.
     */
    public function test_a_percent_wins_over_the_discount_price_column(): void
    {
        $row = $this->analyzeRow([
            'AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1000', '0,5', '999', '',
        ]);

        $this->assertSame('ok', $row['type']);
        $this->assertSame("50\u{00A0}%", $row['discount']);
        $this->assertSame('500', $row['final']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $product = Product::where('sku', '510028')->sole();

        $this->assertNull($product->discount_price);
        $this->assertSame('0.5000', $product->discount);
        $this->assertSame(500.0, $product->finalPrice());
    }

    /**
     * Цена со скидкой округляется до целого тем же правилом, что и розничная.
     */
    public function test_a_discount_price_is_rounded_to_the_nearest_whole_number(): void
    {
        $row = $this->analyzeRow([
            'AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '130', '', '120.5', '',
        ]);

        $this->assertSame('121', $row['final']);
    }

    /**
     * Нечитаемая цена со скидкой строку не отклоняет: скидки у товара просто не будет,
     * и продаваться он станет по розничной.
     */
    public function test_an_unreadable_discount_price_leaves_the_product_at_its_retail_price(): void
    {
        $row = $this->analyzeRow([
            'AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '130', '', '120 манат', '',
        ]);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('', $row['final']);
        $this->assertStringContainsString('120 манат', $row['message']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $product = Product::where('sku', '510028')->sole();

        $this->assertNull($product->discount_price);
        $this->assertSame(130.0, $product->finalPrice());
    }

    /**
     * Товар без скидки оставляет колонку «Цена со скидкой» пустой — иначе строка,
     * вернувшись из браузера, прочиталась бы как «прайс назвал цену, равную розничной»,
     * и своя цена со скидкой появилась бы у всего каталога.
     */
    public function test_a_row_without_a_discount_leaves_the_discount_price_column_empty(): void
    {
        $row = $this->analyzeRow([
            'AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '130', '', '', '',
        ]);

        $this->assertSame('ok', $row['type']);
        $this->assertSame('', $row['final']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertNull(Product::where('sku', '510028')->sole()->discount_price);
    }

    /**
     * Прайс задаёт скидку целиком: товар, которому файл в прошлый раз назвал цену со
     * скидкой, а в этот объявил процент, не остаётся при старой цене — карточка
     * заводится заново, и в ней только то, что сказал новый файл.
     */
    public function test_a_later_price_list_with_a_percent_clears_the_stored_discount_price(): void
    {
        Product::factory()->create([
            'sku' => '510028',
            'main_code' => 'AA1001',
            'barcode' => '8011003993802',
            'price' => 130,
            'discount' => 0,
            'discount_price' => 120,
        ]);

        $row = $this->analyzeRow([
            'AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '130', '10 %', '117', '',
        ]);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $product = Product::where('sku', '510028')->sole();

        $this->assertNull($product->discount_price);
        $this->assertSame('0.1000', $product->discount);
        $this->assertSame(117.0, $product->finalPrice());
    }

    public function test_a_fractional_percent_keeps_its_decimals(): void
    {
        $row = $this->analyzeRow(['AA1002', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '2000.00', '0,335']);

        $this->assertSame('ok', $row['type']);
        $this->assertSame("33,5\u{00A0}%", $row['discount']);
    }

    /**
     * @return list<array{0: string, 1: float}>
     */
    public static function discountNotations(): array
    {
        return [
            'доля из выгрузки' => ['0,3', 0.3],
            'процент со знаком' => ['30 %', 0.3],
            'процент без знака' => ['30', 0.3],
        ];
    }

    /**
     * Колонка «Скидки» принимает и долю, и процент — оператор правит строку так, как
     * читает её в таблице, а в карточку всё равно ложится доля.
     */
    #[DataProvider('discountNotations')]
    public function test_a_discount_is_accepted_as_a_percent_or_a_fraction(string $written, float $stored): void
    {
        $row = $this->analyzeRow(['AA1002', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '2000.00', $written]);

        $this->assertSame('ok', $row['type']);
        $this->assertSame("30\u{00A0}%", $row['discount']);

        $admin = $this->admin();
        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => 'discounts.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertSame($stored, (float) Product::where('sku', '510030')->sole()->discount);
    }

    public function test_a_blank_main_code_is_a_warning_not_an_error(): void
    {
        $row = $this->analyzeRow(['', '512044', '8011003819001', 'HUGO BOSS BOTTLED EDT 50ML', '1640.00', '']);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('НОВЫЙ', $row['tag']);
        $this->assertNull($row['field']);
    }

    /**
     * A blank barcode does not block the row — the product is simply saved without one,
     * see {@see self::test_confirming_a_blank_barcode_saves_the_product_without_one()}.
     */
    public function test_a_blank_barcode_is_a_warning_not_an_error(): void
    {
        $row = $this->analyzeRow(['AA1001', '512044', '', 'HUGO BOSS BOTTLED EDT 50ML', '1640.00', '']);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('НОВЫЙ', $row['tag']);
        $this->assertNull($row['field']);
        $this->assertSame('', $row['barcode']);
    }

    /**
     * Confirming a row with both codes blank creates the product — nothing about the
     * missing pair stops the import, and the operator sees one combined notice for it.
     */
    public function test_a_row_with_both_codes_blank_is_a_single_warning(): void
    {
        $row = $this->analyzeRow(['', '512044', '', 'HUGO BOSS BOTTLED EDT 50ML', '1640.00', '']);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('НОВЫЙ', $row['tag']);
        $this->assertStringContainsString('Основной код пуст', $row['message']);
        $this->assertStringContainsString('Штрихкод пуст', $row['message']);
    }

    /**
     * Прайс сохраняется как есть: штрихкод за поставщика не придумывается, у товара его
     * просто нет. В базу пустая ячейка уходит как NULL — пустых строк уникальный индекс
     * пустил бы только одну.
     */
    public function test_confirming_a_blank_barcode_saves_the_product_without_one(): void
    {
        $row = $this->analyzeRow(['AA1001', '512044', '', 'HUGO BOSS BOTTLED EDT 50ML', '1640.00', '']);

        $admin = $this->admin();
        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertNull(Product::where('sku', '512044')->sole()->barcode);
    }

    /**
     * Товаров без штрихкода в каталоге может быть сколько угодно: колонка допускает
     * NULL, и уникальный индекс их друг с другом не сталкивает.
     */
    public function test_confirming_many_blank_barcodes_saves_them_all(): void
    {
        $rows = [];

        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['', (string) (600000 + $i), '', "PRODUCT {$i}", '100.00', ''];
        }

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $this->workbook($rows)]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertSame(30, Product::whereNull('barcode')->count());
    }

    /**
     * Пустая ячейка штрихкода — это товар без штрихкода, а не «оставить прежний»:
     * карточки, которая стояла в каталоге, после импорта уже нет.
     */
    public function test_confirming_a_blank_barcode_saves_the_new_card_without_one(): void
    {
        $existing = Product::factory()->create(['sku' => '512044', 'barcode' => '8011003993802']);

        $row = $this->analyzeRow(['AA1001', '512044', '', 'HUGO BOSS BOTTLED EDT 50ML', '1640.00', '']);

        $admin = $this->admin();
        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertDatabaseMissing('products', ['id' => $existing->id]);
        $this->assertNull(Product::where('sku', '512044')->sole()->barcode);
    }

    /**
     * Основной код, занятый карточкой каталога, строке ничем не мешает: каталог уходит
     * целиком, и код освобождается вместе с ним.
     */
    public function test_a_main_code_already_used_in_the_catalogue_is_not_a_warning(): void
    {
        Product::factory()->create(['sku' => '999999', 'main_code' => 'AA9999']);

        $row = $this->analyzeRow(['AA9999', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->assertSame('ok', $row['type']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertDatabaseCount('products', 1);
        $this->assertSame('AA9999', Product::where('sku', '510028')->sole()->main_code);
    }

    /**
     * Основной код — код самой панели, и уникальным он остаётся: повторённый в файле
     * строка не отбирает, а получает свободный. Отказом это не считается.
     */
    public function test_a_main_code_repeated_in_the_file_is_reassigned(): void
    {
        $file = $this->workbook([
            ['AA1500', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1500', '999999', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->assertSame('ok', $props['rows'][0]['type']);
        $this->assertSame('warn', $props['rows'][1]['type']);
        $this->assertSame('ДУБЛЬ', $props['rows'][1]['tag']);
        $this->assertStringContainsString('510028', $props['rows'][1]['message']);

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        /* Код достаётся первой строке, вторая получает свободный из серии. */
        $this->assertSame('AA1500', Product::where('sku', '510028')->sole()->main_code);
        $this->assertNotSame('AA1500', Product::where('sku', '999999')->sole()->main_code);
    }

    /**
     * Код из файла достаётся своей строке, даже когда до неё генератор уже раздавал
     * коды: коды прайса резервируются все разом, до записи,
     * см. {@see ImportService::apply()}.
     */
    public function test_a_generated_main_code_never_takes_one_the_file_claims_later(): void
    {
        $file = $this->workbook([
            ['', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1001', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertSame('AA1001', Product::where('sku', '510030')->sole()->main_code);
        $this->assertNotSame('AA1001', Product::where('sku', '510028')->sole()->main_code);
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

    /**
     * Пропущенных строк больше не бывает: даже строка без номенклатуры заводит товар, и
     * в истории загрузок у такого импорта ноль неудач.
     */
    public function test_confirming_creates_every_row_including_the_incomplete_ones(): void
    {
        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1002', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', '0,5'],
            ['AA1003', '510032', '8011003993826', '', '2426.04', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $response = $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ]);

        $response->assertRedirect('/import');

        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseHas('products', ['sku' => '510028', 'name' => 'VERSACE BRIGHT CRYSTAL EDT 30ML']);
        $this->assertDatabaseHas('products', ['sku' => '510032', 'name' => '']);
        $created = Product::where('sku', '510030')->sole();
        $this->assertSame(0.5, (float) $created->discount);

        $this->assertDatabaseHas('import_batches', [
            'file_name' => 'price-list.xlsx',
            'rows_ok' => 3,
            'rows_failed' => 0,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Импорт из Excel',
            'object' => 'price-list.xlsx',
            'value_to' => '3 строк',
            'kind' => 'import',
        ]);
    }

    /**
     * Прайс — это каталог целиком: товар, которого в файле не оказалось, удаляется
     * вместе со своей историей цен. В каталоге после импорта ровно то, что было в файле.
     */
    public function test_confirming_deletes_the_products_the_file_does_not_mention(): void
    {
        $doomed = Product::factory()->create(['sku' => '999999', 'main_code' => 'AA9999', 'barcode' => '8011003990001']);
        $doomed->priceHistories()->create([
            'changed_at' => now(),
            'author' => 'tester',
            'reason' => 'ручная правка',
            'price_from' => 100,
            'price_to' => 200,
        ]);

        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        /* Окно подтверждения называет это число оператору до нажатия «Импортировать». */
        $this->assertSame(1, $props['obsolete']);

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseMissing('products', ['sku' => '999999']);
        $this->assertDatabaseMissing('price_histories', ['product_id' => $doomed->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Каталог заменён прайсом',
            'object' => 'price-list.xlsx',
            'value_from' => '1 товаров',
            'value_to' => '1 товаров',
            'kind' => 'import',
        ]);
    }

    /**
     * Остатки по точкам и сканы уходят вслед за товаром: каскад внешних ключей — то, на
     * чём держится замена каталога одним DELETE, {@see ProductRepository::deleteAll()}.
     */
    public function test_replacing_the_catalogue_takes_the_stock_and_the_scans_with_it(): void
    {
        $doomed = Product::factory()->create(['sku' => '999999', 'main_code' => 'AA9999']);
        $point = Point::factory()->create();
        $doomed->stocks()->create(['point_id' => $point->id, 'qty' => 7]);

        $row = $this->analyzeRow(['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertDatabaseMissing('products', ['id' => $doomed->id]);
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $doomed->id]);
    }

    /**
     * Строка с нечитаемой ценой свой товар больше не теряет: она проходит, цена
     * становится нулём, а карточка остаётся в каталоге.
     */
    public function test_a_product_whose_row_has_an_unreadable_price_survives_the_import(): void
    {
        Product::factory()->create(['sku' => '510028', 'main_code' => 'AA1001', 'barcode' => '8011003993802']);

        $file = $this->workbook([
            ['AA1002', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', 'цена не число', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->assertSame('warn', $props['rows'][1]['type']);

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['sku' => '510028', 'price' => '0.00']);
    }

    /**
     * Тот же прайс, загруженный трижды, каталог не удваивает и не разращивает: каждый
     * импорт стирает всё и заводит заново ровно то, что в файле.
     */
    public function test_re_importing_the_same_file_leaves_only_the_rows_of_that_file(): void
    {
        Product::factory()->create(['sku' => '999999', 'main_code' => 'AA9999', 'barcode' => '8011003990001']);

        $rows = [
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
            ['AA1002', '510030', '8011003993819', 'VERSACE BRIGHT CRYSTAL EDT 50ML', '1920.96', ''],
        ];

        $admin = $this->admin();

        foreach (range(1, 3) as $ignored) {
            $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $this->workbook($rows)]));

            $this->actingAs($admin)->post('/import/confirm', [
                'fileName' => $props['fileName'],
                'storedPath' => $props['storedPath'],
                'rows' => $props['rows'],
            ])->assertRedirect('/import');
        }

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['sku' => '510028', 'main_code' => 'AA1001']);
        $this->assertDatabaseHas('products', ['sku' => '510030', 'main_code' => 'AA1002']);
        $this->assertDatabaseMissing('products', ['sku' => '999999']);
    }

    /**
     * Пустая или испорченная загрузка каталог не трогает вовсе: ни одной строки не
     * пришло — ни одна карточка не изменилась.
     */
    public function test_a_file_without_a_single_row_changes_nothing(): void
    {
        Product::factory()->create(['sku' => '510028']);

        $admin = $this->admin();

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => 'broken.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [],
        ])->assertRedirect('/import');

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['sku' => '510028']);
    }

    /**
     * Переименованный поставщиком товар — не «новый рядом со старым»: старая карточка
     * уходит вместе со всем каталогом, а в базе остаётся одна, с артикулом из файла.
     */
    public function test_a_renamed_article_does_not_leave_a_second_card_behind(): void
    {
        $existing = Product::factory()->create(['sku' => '999999', 'barcode' => '8011003993802']);

        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
        ]);

        $admin = $this->admin();
        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $file]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseMissing('products', ['id' => $existing->id]);
        $this->assertSame('8011003993802', Product::where('sku', '510028')->sole()->barcode);
    }

    public function test_the_backup_download_returns_the_whole_catalog_as_backup1(): void
    {
        Product::factory()->count(2)->create();

        $response = $this->actingAs($this->admin())->get('/import/backup');

        $response->assertOk()->assertDownload('backup1.xlsx');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Резервная копия перед импортом',
            'object' => 'backup1.xlsx',
            'kind' => 'import',
        ]);
    }

    public function test_a_seller_cannot_download_the_pre_import_backup(): void
    {
        $this->actingAs(User::factory()->create())->get('/import/backup')->assertForbidden();
    }

    /**
     * Карточка заводится заново целиком: прежнее имя, цена и статус не переживают
     * импорт. Скрытый из продажи товар после замены каталога снова в продаже — скрывать
     * его нужно заново, руками.
     */
    public function test_confirming_replaces_an_existing_product_with_a_fresh_card_on_sale(): void
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
        $this->assertDatabaseMissing('products', ['id' => $existing->id]);

        $product = Product::where('sku', '510028')->sole();
        $this->assertSame('NEW NAME', $product->name);
        $this->assertSame(1416.0, (float) $product->price);
        $this->assertSame('active', $product->status->value);

        /* Истории цены у новой карточки нет: сравнивать импорту не с чем. */
        $this->assertDatabaseCount('price_histories', 0);
    }

    /**
     * Артикул строки каталогу незнаком, а её штрихкод числится за карточкой — спора
     * нет: карточка уходит вместе со всем каталогом, и остаётся ровно строка файла.
     */
    public function test_confirming_replaces_a_product_that_held_the_same_barcode(): void
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
        $this->assertDatabaseMissing('products', ['id' => $existing->id]);

        $product = Product::where('sku', '510028')->sole();
        $this->assertSame('NEW NAME', $product->name);
        $this->assertSame(1416.0, (float) $product->price);
    }

    /**
     * То же самое, но карточка держала основной код строки, а не её штрихкод.
     */
    public function test_confirming_replaces_a_product_that_held_the_same_main_code(): void
    {
        $existing = Product::factory()->create([
            'sku' => '999999',
            'main_code' => 'AA9999',
            'barcode' => '8011003990001',
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
        $this->assertDatabaseMissing('products', ['id' => $existing->id]);
        $this->assertSame('NEW NAME', Product::where('sku', '510028')->sole()->name);
    }

    /**
     * Колонка H прайса — оптовая цена. Пустая ячейка не «оставляет прежнюю»: карточки,
     * в которой опт стоял, после импорта уже нет, и товар заводится без него.
     */
    public function test_confirming_writes_the_wholesale_price_and_a_blank_column_leaves_it_empty(): void
    {
        Product::factory()->create([
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

        $this->assertSame('921', $props['rows'][0]['wholesale']);

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertSame('921.00', Product::where('sku', '510028')->sole()->wholesale_price);

        $blank = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
        ]);

        $props = $this->props($this->actingAs($admin)->post('/import', ['file' => $blank]));

        $this->actingAs($admin)->post('/import/confirm', [
            'fileName' => $props['fileName'],
            'storedPath' => $props['storedPath'],
            'rows' => $props['rows'],
        ])->assertRedirect('/import');

        $this->assertNull(Product::where('sku', '510028')->sole()->wholesale_price);
    }

    /**
     * Нечитаемый опт строку не отклоняет: он приравнивается к пустой ячейке, и товар
     * заводится без оптовой цены.
     */
    public function test_a_non_numeric_wholesale_price_leaves_the_card_without_one(): void
    {
        Product::factory()->create(['sku' => '510028', 'wholesale_price' => 900]);

        $row = $this->analyzeRow([
            'AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', '', '', '920 манат',
        ]);

        $this->assertSame('warn', $row['type']);
        $this->assertSame('', $row['wholesale']);
        $this->assertStringContainsString('920 манат', $row['message']);

        $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => $this->fakeStoredFile(),
            'rows' => [$row],
        ])->assertRedirect('/import');

        $this->assertNull(Product::where('sku', '510028')->sole()->wholesale_price);
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
     * apply() разбирает строку заново, а не берёт присланное на веру: значения
     * приводятся к тому, что примет каталог, независимо от того, что о строке сообщил
     * браузер. Отклонить строку нельзя, но и подсунуть через неё число, которого колонка
     * не выдержит, тоже.
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
                'sku' => '510028',
                'barcode' => '8011003993802',
                'name' => str_repeat('Ц', 400),
                'retail' => '99999999999',
                'discount' => '900',
                'type' => 'ok',
            ]],
        ]);

        $response->assertRedirect('/import');

        $product = Product::sole();
        $this->assertSame(255, mb_strlen($product->name));
        $this->assertSame('99999999.00', $product->price);
        $this->assertSame('1.0000', $product->discount);
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

    /**
     * Диск отказал в записи — в докере это чужой владелец у storage/app/private/imports.
     * Разбор строк к этому моменту уже прошёл, но без сохранённого файла подтверждать
     * нечего, поэтому мастер обязан сказать об этом сразу и по-русски: раньше false
     * молча уезжал в storedPath, и оператор упирался в английское «must be a string»
     * только на кнопке «Импортировать».
     */
    public function test_an_upload_the_disk_refuses_to_store_is_rejected_in_russian(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->once()->andReturnFalse();
        Storage::set('local', $disk);

        $file = $this->workbook([
            ['AA1001', '510028', '8011003993802', 'VERSACE BRIGHT CRYSTAL EDT 30ML', '1415.88', ''],
        ]);

        $response = $this->actingAs($this->admin())->post('/import', ['file' => $file]);

        $response->assertSessionHasErrors([
            'file' => 'Файл разобран, но не сохранился на сервере: каталог storage/app/private/imports закрыт на запись. Проверьте права на папку и загрузите файл заново.',
        ]);
    }

    /**
     * Та же беда, пойманная на шаге подтверждения: путь не строка, потому что сохранить
     * файл не удалось. Сообщение должно быть русским — оно уходит оператору в
     * уведомление, см. resources/js/Pages/Import/Index.vue.
     */
    public function test_confirming_with_a_path_that_never_got_stored_is_rejected_in_russian(): void
    {
        $response = $this->actingAs($this->admin())->post('/import/confirm', [
            'fileName' => 'price-list.xlsx',
            'storedPath' => false,
            'rows' => [],
        ]);

        $response->assertSessionHasErrors([
            'storedPath' => 'Файл прайса не сохранился на сервере, импортировать нечего. Начните заново с шага «Файл»: загрузите прайс ещё раз.',
        ]);
        $this->assertDatabaseCount('import_batches', 0);
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
