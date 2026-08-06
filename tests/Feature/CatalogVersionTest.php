<?php

namespace Tests\Feature;

use App\Models\CatalogVersion;
use App\Models\Device;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogVersionService;
use App\Services\ImportService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Ревизия каталога: число, по которому телефон понимает, что отстал. Растёт от правок
 * каталога и только от них — вход сотрудника или переключение модуля её не двигают.
 */
class CatalogVersionTest extends TestCase
{
    use RefreshDatabase;

    private CatalogVersionService $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->version = app(CatalogVersionService::class);
    }

    /**
     * Пустая база — пустой каталог: телефону нечего забирать, пока не появился товар.
     */
    public function test_a_fresh_install_starts_at_zero(): void
    {
        $this->assertSame(0, $this->version->current());
    }

    /**
     * Таблицы могут вычистить целиком — счётчик обязан подняться заново, а не застрять.
     */
    public function test_the_counter_rebuilds_its_row_after_the_table_is_emptied(): void
    {
        CatalogVersion::query()->delete();
        Cache::flush();

        $this->assertSame(1, $this->version->bump());
    }

    public function test_creating_a_product_publishes_a_new_revision(): void
    {
        $before = $this->version->current();

        app(ProductService::class)->create([
            'name' => 'ОСЕННИЙ ПАРФЮМ',
            'sku' => 'AR-9001',
            'barcode' => ProductService::ean13('801100399123'),
            'price' => 420.0,
            'discount' => 0.0,
            'status' => 'active',
        ], 'Администратор');

        $this->assertSame($before + 1, $this->version->current());
    }

    public function test_a_price_change_publishes_a_new_revision(): void
    {
        $product = Product::factory()->create(['price' => 100]);
        $before = $this->version->current();

        app(ProductService::class)->update($product, [
            'main_code' => $product->main_code,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'price' => 150.0,
            'discount' => (float) $product->discount,
            'status' => $product->status->value,
        ], 'Администратор');

        $this->assertSame($before + 1, $this->version->current());
    }

    /**
     * Карточку открыли и сохранили как есть — телефонам нечего забирать.
     */
    public function test_saving_a_product_unchanged_leaves_the_revision_alone(): void
    {
        $product = Product::factory()->create();
        $before = $this->version->current();

        app(ProductService::class)->update($product, [
            'main_code' => $product->main_code,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'price' => (float) $product->price,
            'discount' => (float) $product->discount,
            'status' => $product->status->value,
        ], 'Администратор');

        $this->assertSame($before, $this->version->current());
    }

    public function test_hiding_products_publishes_one_revision_for_the_whole_selection(): void
    {
        $ids = Product::factory()->count(3)->create()->pluck('id')->all();
        $before = $this->version->current();

        app(ProductService::class)->hide($ids, 'Администратор');

        $this->assertSame($before + 1, $this->version->current());
    }

    public function test_a_bulk_price_edit_publishes_one_revision(): void
    {
        $ids = Product::factory()->count(4)->create(['price' => 200])->pluck('id')->all();
        $before = $this->version->current();

        app(ProductService::class)->applyBulk($ids, 'percent', 10, 'Администратор');

        $this->assertSame($before + 1, $this->version->current());
    }

    /**
     * Прайс на 500 строк — одна публикация каталога, а не 500 ревизий.
     */
    public function test_an_import_publishes_one_revision_for_the_whole_file(): void
    {
        $before = $this->version->current();

        $result = app(ImportService::class)->apply([
            ['row' => 1, 'mainCode' => '', 'sku' => 'AR-7001', 'barcode' => ProductService::ean13('801100399201'), 'name' => 'ПЕРВЫЙ', 'retail' => '120', 'discount' => ''],
            ['row' => 2, 'mainCode' => '', 'sku' => 'AR-7002', 'barcode' => ProductService::ean13('801100399202'), 'name' => 'ВТОРОЙ', 'retail' => '130', 'discount' => ''],
        ], 'price.xlsx', 'Администратор');

        $this->assertSame(2, $result['ok']);

        $this->assertSame($before + 1, $this->version->current());
        $this->assertSame(2, Product::whereIn('sku', ['AR-7001', 'AR-7002'])->count());
    }

    /**
     * Ради этого числа всё и затевалось: телефон, синхронизировавшийся до правки цен,
     * должен увидеть отставание — а раздел «Синхронизация» покрасить его строку.
     */
    public function test_a_device_falls_behind_after_the_catalogue_changes(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);

        $this->asDevice($seller)->postJson('/api/v1/sync', ['data_version' => 0])->assertOk();

        $synced = $this->version->current();
        app(ProductService::class)->hide(Product::factory()->count(2)->create()->pluck('id')->all(), 'Администратор');

        $this->asDevice($seller)
            ->postJson('/api/v1/sync', ['data_version' => $synced])
            ->assertOk()
            ->assertJsonPath('data.catalog_version', $synced + 1)
            ->assertJsonPath('data.lag', 1)
            ->assertJsonPath('data.up_to_date', false);
    }

    public function test_the_section_shows_the_current_revision(): void
    {
        Device::factory()->create(['user_id' => User::factory()->create(['role' => 'seller']), 'point_id' => null]);

        app(ProductService::class)->hide(Product::factory()->count(1)->create()->pluck('id')->all(), 'Администратор');

        $this->actingAs($this->admin())
            ->get('/devices')
            ->assertInertia(fn ($page) => $page->where('catalogVersion', $this->version->current()));
    }
}
