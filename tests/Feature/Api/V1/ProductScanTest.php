<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\ProductScan;
use App\Models\RoleFieldRight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * История последних просканированных товаров: пишет её сервер на успешном поиске по
 * штрихкоду, отдельного вызова «залогируй скан» у приложения нет.
 */
class ProductScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    private function seller(): User
    {
        return User::factory()->create(['role' => 'seller']);
    }

    public function test_a_barcode_lookup_writes_the_product_into_the_history(): void
    {
        $product = Product::factory()->create(['barcode' => '8011003993802']);
        $seller = $this->seller();

        $this->asDevice($seller, 'Samsung A15')
            ->getJson('/api/v1/products/8011003993802')
            ->assertOk();

        $this->assertDatabaseHas('product_scans', [
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'device' => 'Samsung A15',
            'times' => 1,
        ]);
    }

    public function test_an_unknown_barcode_leaves_no_trace(): void
    {
        $this->asDevice($this->seller())
            ->getJson('/api/v1/products/8011003990000')
            ->assertNotFound();

        $this->assertDatabaseCount('product_scans', 0);
    }

    public function test_scanning_the_same_product_twice_bumps_the_row_instead_of_duplicating_it(): void
    {
        Product::factory()->create(['barcode' => '8011003993802']);
        $seller = $this->seller();

        $this->asDevice($seller)->getJson('/api/v1/products/8011003993802')->assertOk();
        $first = ProductScan::sole();

        $this->travel(2)->minutes();
        $this->getJson('/api/v1/products/8011003993802')->assertOk();

        $this->assertDatabaseCount('product_scans', 1);

        $second = ProductScan::sole();
        $this->assertSame(2, $second->times);
        $this->assertTrue($second->scanned_at->greaterThan($first->scanned_at));
    }

    public function test_the_history_returns_the_newest_scan_first(): void
    {
        Product::factory()->create(['barcode' => '8011003993802', 'name' => 'ПЕРВЫЙ']);
        Product::factory()->create(['barcode' => '8011003993819', 'name' => 'ВТОРОЙ']);

        $this->asDevice($this->seller())->getJson('/api/v1/products/8011003993802')->assertOk();
        $this->travel(1)->minutes();
        $this->getJson('/api/v1/products/8011003993819')->assertOk();

        $rows = $this->getJson('/api/v1/products/recent')
            ->assertOk()
            ->assertJsonStructure(['data' => [['scanned_at', 'times', 'product' => ['id', 'name']]], 'meta' => ['limit', 'server_time']])
            ->json('data');

        $this->assertSame(['ВТОРОЙ', 'ПЕРВЫЙ'], array_column(array_column($rows, 'product'), 'name'));
    }

    public function test_the_history_limit_comes_from_the_request_and_is_capped(): void
    {
        $seller = $this->seller();
        ProductScan::factory()->count(4)->create(['user_id' => $seller->id]);

        $this->asDevice($seller)
            ->getJson('/api/v1/products/recent?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.limit', 2);

        $this->getJson('/api/v1/products/recent?limit=999')
            ->assertOk()
            ->assertJsonPath('meta.limit', config('aroma.scan_history.max'));
    }

    public function test_the_history_belongs_to_the_seller_not_to_the_shop(): void
    {
        $mine = $this->seller();
        ProductScan::factory()->create(['user_id' => $mine->id]);
        ProductScan::factory()->create(['user_id' => $this->seller()->id]);

        $this->asDevice($mine)
            ->getJson('/api/v1/products/recent')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_product_hidden_after_the_scan_leaves_the_history(): void
    {
        $product = Product::factory()->create(['barcode' => '8011003993802']);
        $seller = $this->seller();

        $this->asDevice($seller)->getJson('/api/v1/products/8011003993802')->assertOk();
        $this->getJson('/api/v1/products/recent')->assertOk()->assertJsonCount(1, 'data');

        $product->update(['status' => 'hidden']);

        $this->getJson('/api/v1/products/recent')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('product_scans', 1);
    }

    public function test_the_history_respects_the_field_policy(): void
    {
        Product::factory()->create(['barcode' => '8011003993802', 'main_code' => 'AA1001']);

        RoleFieldRight::updateOrCreate(['role' => 'seller', 'field' => 'mainCode'], ['visible' => false]);
        Cache::flush();

        $this->asDevice($this->seller())->getJson('/api/v1/products/8011003993802')->assertOk();

        $row = $this->getJson('/api/v1/products/recent')->assertOk()->json('data.0.product');

        $this->assertArrayNotHasKey('main_code', $row);
    }

    public function test_the_seller_can_clear_the_history(): void
    {
        $seller = $this->seller();
        ProductScan::factory()->count(3)->create(['user_id' => $seller->id]);

        $this->asDevice($seller)
            ->deleteJson('/api/v1/products/recent')
            ->assertOk()
            ->assertJsonPath('data.cleared', true);

        $this->assertDatabaseCount('product_scans', 0);
    }

    public function test_the_history_is_not_public(): void
    {
        $this->getJson('/api/v1/products/recent')->assertUnauthorized();
        $this->deleteJson('/api/v1/products/recent')->assertUnauthorized();
    }
}
