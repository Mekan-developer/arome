<?php

namespace Tests\Feature\Api\V1;

use App\Models\Point;
use App\Models\Product;
use App\Models\RoleFieldRight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Тесты v1 не правятся при разработке v2 — они и есть гарантия, что v1 не сломан.
 */
class ProductApiTest extends TestCase
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

    public function test_it_finds_a_product_by_barcode(): void
    {
        Product::factory()->create([
            'barcode' => '8011003993802',
            'name' => 'LATTAFA KHAMRAH EDP 100ML',
            'price' => 420,
        ]);

        $this->asDevice($this->seller())
            ->getJson('/api/v1/products/8011003993802')
            ->assertOk()
            ->assertJsonPath('data.name', 'LATTAFA KHAMRAH EDP 100ML')
            ->assertJsonPath('data.barcode', '8011003993802')
            ->assertJsonStructure(['data' => ['id', 'status'], 'meta' => ['server_time']]);
    }

    public function test_the_list_does_not_carry_hidden_products(): void
    {
        Product::factory()->create(['name' => 'ACTIVE ONE']);
        Product::factory()->hidden()->create(['name' => 'HIDDEN ONE']);

        $rows = $this->asDevice($this->seller())
            ->getJson('/api/v1/products')
            ->assertOk()
            ->json('data');

        $this->assertSame(['ACTIVE ONE'], array_column($rows, 'name'));
    }

    public function test_the_status_filter_cannot_reveal_hidden_products(): void
    {
        Product::factory()->create();
        Product::factory()->hidden()->create();

        $this->asDevice($this->seller())
            ->getJson('/api/v1/products?status=hidden')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_hidden_product_is_not_found_by_barcode(): void
    {
        Product::factory()->hidden()->create(['barcode' => '8011003993802']);

        $this->asDevice($this->seller())
            ->getJson('/api/v1/products/8011003993802')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'product_not_found');
    }

    public function test_an_unknown_barcode_answers_with_a_code_not_a_message(): void
    {
        $this->asDevice($this->seller())
            ->getJson('/api/v1/products/8011003990000')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'product_not_found');
    }

    public function test_the_barcode_lookup_respects_the_field_policy(): void
    {
        Product::factory()->create(['barcode' => '8011003993802', 'main_code' => 'AA1001']);

        RoleFieldRight::updateOrCreate(['role' => 'seller', 'field' => 'mainCode'], ['visible' => false]);
        Cache::flush();

        $row = $this->asDevice($this->seller())
            ->getJson('/api/v1/products/8011003993802')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('main_code', $row);
    }

    public function test_the_search_finds_a_product_by_name_and_by_sku(): void
    {
        Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML', 'sku' => '510028']);
        Product::factory()->create(['name' => 'VERSACE EROS EDT 50ML', 'sku' => '510041']);

        $seller = $this->seller();

        $byName = $this->asDevice($seller)->getJson('/api/v1/products?q=KHAMRAH')->assertOk()->json('data');
        $this->assertSame(['LATTAFA KHAMRAH EDP 100ML'], array_column($byName, 'name'));

        $bySku = $this->getJson('/api/v1/products?q=510041')->assertOk()->json('data');
        $this->assertSame(['VERSACE EROS EDT 50ML'], array_column($bySku, 'name'));
    }

    public function test_the_search_does_not_reach_a_field_hidden_from_the_role(): void
    {
        Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML', 'main_code' => 'AA1001']);

        RoleFieldRight::updateOrCreate(['role' => 'seller', 'field' => 'mainCode'], ['visible' => false]);
        Cache::flush();

        $seller = $this->seller();

        // Скрытый код нельзя восстановить перебором префиксов через ?q=…
        $this->asDevice($seller)
            ->getJson('/api/v1/products?q=AA10')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // …а видимые поля продолжают искаться.
        $this->getJson('/api/v1/products?q=KHAMRAH')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_list_is_sorted_by_the_main_code_by_default(): void
    {
        Product::factory()->create(['name' => 'VERSACE EROS EDT 50ML', 'main_code' => 'AA1003']);
        Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML', 'main_code' => 'AA1001']);
        Product::factory()->create(['name' => 'ARMAF CLUB DE NUIT EDT 105ML', 'main_code' => 'AA1002']);

        $rows = $this->asDevice($this->seller())
            ->getJson('/api/v1/products')
            ->assertOk()
            ->json('data');

        $this->assertSame(['AA1001', 'AA1002', 'AA1003'], array_column($rows, 'main_code'));
    }

    public function test_an_explicit_sort_still_wins_over_the_main_code(): void
    {
        Product::factory()->create(['name' => 'VERSACE EROS EDT 50ML', 'main_code' => 'AA1001']);
        Product::factory()->create(['name' => 'ARMAF CLUB DE NUIT EDT 105ML', 'main_code' => 'AA1002']);

        $rows = $this->asDevice($this->seller())
            ->getJson('/api/v1/products?sort=name')
            ->assertOk()
            ->json('data');

        $this->assertSame(['AA1002', 'AA1001'], array_column($rows, 'main_code'));
    }

    public function test_the_full_catalogue_comes_in_one_response_without_paging(): void
    {
        Product::factory()->count(30)->create();

        $body = $this->asDevice($this->seller())
            ->getJson('/api/v1/products_all')
            ->assertOk()
            ->json();

        $this->assertCount(30, $body['data']);
        $this->assertArrayNotHasKey('has_more', $body['meta']);
        $this->assertArrayHasKey('server_time', $body['meta']);
    }

    /**
     * Выгрузка приходит порядком прайса, а не порядком заведения карточек: приложение
     * заливает её в локальную базу и показывает тем же порядком.
     */
    public function test_the_full_catalogue_is_sorted_by_the_main_code(): void
    {
        Product::factory()->create(['name' => 'VERSACE EROS EDT 50ML', 'main_code' => 'AA1003']);
        Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML', 'main_code' => 'AA1001']);
        Product::factory()->create(['name' => 'ARMAF CLUB DE NUIT EDT 105ML', 'main_code' => 'AA1002']);

        $rows = $this->asDevice($this->seller())
            ->getJson('/api/v1/products_all')
            ->assertOk()
            ->json('data');

        $this->assertSame(['AA1001', 'AA1002', 'AA1003'], array_column($rows, 'main_code'));
    }

    /**
     * Прайс приносит карточки без основного кода — их место в конце выгрузки, а не
     * перед всем каталогом.
     */
    public function test_the_full_catalogue_puts_products_without_a_main_code_last(): void
    {
        Product::factory()->create(['main_code' => null]);
        Product::factory()->create(['main_code' => 'AA1002']);
        Product::factory()->create(['main_code' => 'AA1001']);

        $rows = $this->asDevice($this->seller())
            ->getJson('/api/v1/products_all')
            ->assertOk()
            ->json('data');

        $this->assertSame(['AA1001', 'AA1002', null], array_column($rows, 'main_code'));
    }

    /**
     * `per_page`, `q`, `status`, `sort` — здесь это просто мусор в адресе: выгрузка
     * отдаёт каталог целиком, что бы в запросе ни стояло.
     */
    public function test_the_full_catalogue_ignores_the_list_query_parameters(): void
    {
        Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML']);
        Product::factory()->create(['name' => 'VERSACE EROS EDT 50ML']);

        $this->asDevice($this->seller())
            ->getJson('/api/v1/products_all?q=KHAMRAH&per_page=1&status=hidden&sort=-price')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_full_catalogue_does_not_carry_hidden_products(): void
    {
        Product::factory()->create(['name' => 'ACTIVE ONE']);
        Product::factory()->hidden()->create(['name' => 'HIDDEN ONE']);

        $rows = $this->asDevice($this->seller())
            ->getJson('/api/v1/products_all')
            ->assertOk()
            ->json('data');

        $this->assertSame(['ACTIVE ONE'], array_column($rows, 'name'));
    }

    public function test_the_full_catalogue_respects_the_field_policy(): void
    {
        Product::factory()->create(['main_code' => 'AA1001']);

        RoleFieldRight::updateOrCreate(['role' => 'seller', 'field' => 'mainCode'], ['visible' => false]);
        Cache::flush();

        $rows = $this->asDevice($this->seller())
            ->getJson('/api/v1/products_all')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('main_code', $rows[0]);
    }

    /**
     * Выгрузка читается пачками, поэтому остатки надо грузить вместе с товаром — иначе
     * связь останется незагруженной и `stock` уедет на устройство пустым.
     */
    public function test_the_full_catalogue_carries_stock_when_the_module_is_on(): void
    {
        $this->seedModules(['points' => true, 'productPoints' => true]);
        $point = Point::factory()->create();
        Product::factory()->create()->stocks()->create(['point_id' => $point->id, 'qty' => 7]);

        $rows = $this->asDevice($this->seller())
            ->getJson('/api/v1/products_all')
            ->assertOk()
            ->json('data');

        $this->assertSame([['point_id' => (string) $point->id, 'qty' => 7]], $rows[0]['stock']);
    }

    public function test_the_full_catalogue_answers_with_an_empty_list_when_there_are_no_products(): void
    {
        $this->asDevice($this->seller())
            ->getJson('/api/v1/products_all')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_full_catalogue_is_not_public(): void
    {
        Product::factory()->create();

        $this->getJson('/api/v1/products_all')->assertUnauthorized();
    }

    public function test_the_barcode_lookup_is_not_public(): void
    {
        Product::factory()->create(['barcode' => '8011003993802']);

        $this->getJson('/api/v1/products/8011003993802')->assertUnauthorized();
    }

    public function test_a_non_numeric_barcode_does_not_match_the_route(): void
    {
        $this->asDevice($this->seller())
            ->getJson('/api/v1/products/not-a-barcode')
            ->assertNotFound();
    }
}
