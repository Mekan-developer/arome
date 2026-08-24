<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RoleFieldRight;
use App\Models\User;
use App\Services\RightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RightsTest extends TestCase
{
    use RefreshDatabase;

    private RightsService $rights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->rights = app(RightsService::class);
    }

    private function seller(): User
    {
        return User::factory()->create(['role' => 'seller']);
    }

    public function test_the_administrator_role_is_immutable(): void
    {
        RoleFieldRight::create(['role' => 'admin', 'field' => 'retail', 'visible' => false]);
        Cache::flush();

        $this->assertTrue(
            $this->rights->matrix()['admin']['retail'],
            'Every field stays visible for the administrator even if a row says otherwise.',
        );
    }

    public function test_saving_the_policy_ignores_the_administrator_row(): void
    {
        $this->actingAs($this->admin())
            ->put('/rights', ['roles' => ['seller' => ['mainCode' => false, 'retail' => false]]])
            ->assertSessionHasNoErrors();

        $matrix = $this->rights->matrix();

        $this->assertFalse($matrix['seller']['mainCode']);
        $this->assertFalse($matrix['seller']['retail']);
        $this->assertTrue($matrix['admin']['retail']);
    }

    /**
     * Продавец и менеджер настраиваются по отдельности: одна строка не тянет за собой
     * другую, иначе разделение ролей теряет смысл.
     */
    public function test_each_role_keeps_its_own_row(): void
    {
        $this->actingAs($this->admin())
            ->put('/rights', ['roles' => [
                'seller' => ['retail' => false],
                'manager' => ['retail' => true, 'wholesale' => false],
            ]])
            ->assertSessionHasNoErrors();

        $matrix = $this->rights->matrix();

        $this->assertFalse($matrix['seller']['retail']);
        $this->assertTrue($matrix['manager']['retail']);
        $this->assertFalse($matrix['manager']['wholesale']);
    }

    /**
     * Оптовая цена — то, чем менеджер отличается от продавца. Без единой строки в базе
     * она уже уходит менеджеру и уже не уходит продавцу.
     */
    public function test_the_wholesale_price_reaches_the_manager_and_not_the_seller(): void
    {
        Product::factory()->create(['price' => 1415.88, 'wholesale_price' => 920]);

        $manager = User::factory()->create(['role' => 'manager']);

        $row = $this->asDevice($manager)->getJson('/api/v1/products')->assertOk()->json('data.0');

        $this->assertSame(92000, $row['wholesale']['amount']);
        $this->assertSame('TMT', $row['wholesale']['currency']);

        $sellerRow = $this->forgetAuthenticatedUser()
            ->asDevice($this->seller())
            ->getJson('/api/v1/products')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('wholesale', $sellerRow);
    }

    public function test_a_product_without_a_wholesale_price_sends_no_wholesale_key(): void
    {
        Product::factory()->create(['wholesale_price' => null]);

        $manager = User::factory()->create(['role' => 'manager']);

        $row = $this->asDevice($manager)->getJson('/api/v1/products')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('wholesale', $row);
    }

    public function test_the_wholesale_column_may_be_opened_for_the_seller(): void
    {
        Product::factory()->create(['wholesale_price' => 920]);

        $this->actingAs($this->admin())
            ->put('/rights', ['roles' => ['seller' => ['wholesale' => true]]])
            ->assertSessionHasNoErrors();

        $row = $this->asDevice($this->seller())->getJson('/api/v1/products')->assertOk()->json('data.0');

        $this->assertSame(92000, $row['wholesale']['amount']);
    }

    /**
     * The stock column is withheld from the panel for now. Its policy row still lives in
     * the database, so a request that names it must not be able to flip it.
     */
    public function test_the_stock_column_is_not_offered_in_the_matrix(): void
    {
        $this->assertNotContains('stock', array_column(RightsService::panelFields(), 'key'));

        $this->actingAs($this->admin())
            ->get('/rights')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'fields',
                fn ($fields): bool => ! in_array('stock', collect($fields)->pluck('key')->all(), true),
            ));

        $this->actingAs($this->admin())
            ->put('/rights', ['roles' => ['seller' => ['stock' => false]]])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->rights->matrix()['seller']['stock']);
    }

    public function test_the_matrix_offers_the_manager_row_and_the_wholesale_column(): void
    {
        $this->actingAs($this->admin())
            ->get('/rights')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('roles', fn ($roles): bool => collect($roles)->contains(
                    fn (array $role): bool => $role['key'] === 'manager' && $role['editable'] === true,
                ))
                ->where('fields', fn ($fields): bool => in_array('wholesale', collect($fields)->pluck('key')->all(), true))
                ->where('matrix', fn ($matrix): bool => $matrix['manager']['wholesale'] === true && $matrix['seller']['wholesale'] === false)
                ->etc(),
            );
    }

    public function test_a_hidden_field_never_reaches_the_products_endpoint(): void
    {
        Product::factory()->create([
            'name' => 'LATTAFA KHAMRAH EDP 100ML',
            'main_code' => 'AA1001',
            'sku' => '510028',
            'barcode' => '8011003993802',
            'price' => 420,
        ]);

        RoleFieldRight::updateOrCreate(['role' => 'seller', 'field' => 'mainCode'], ['visible' => false]);
        RoleFieldRight::updateOrCreate(['role' => 'seller', 'field' => 'barcode'], ['visible' => true]);
        Cache::flush();

        $row = $this->asDevice($this->seller())->getJson('/api/v1/products')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('main_code', $row, 'A hidden field must be absent, not blanked.');
        $this->assertArrayHasKey('barcode', $row);
        $this->assertSame('8011003993802', $row['barcode']);
    }

    public function test_hiding_the_price_removes_both_the_retail_and_the_final_price(): void
    {
        Product::factory()->discounted()->create();

        RoleFieldRight::updateOrCreate(['role' => 'seller', 'field' => 'retail'], ['visible' => false]);
        RoleFieldRight::updateOrCreate(['role' => 'seller', 'field' => 'discount'], ['visible' => false]);
        Cache::flush();

        $row = $this->asDevice($this->seller())->getJson('/api/v1/products')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('retail', $row);
        $this->assertArrayNotHasKey('discount', $row);
        $this->assertArrayNotHasKey('final', $row);
    }

    public function test_the_endpoint_answers_in_the_agreed_envelope(): void
    {
        Product::factory()->count(3)->create();

        $this->asDevice($this->seller())->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'status']],
                'meta' => ['per_page', 'current_page', 'has_more', 'server_time'],
            ]);
    }

    public function test_money_is_an_integer_in_minor_units(): void
    {
        Product::factory()->create(['price' => 1415.88, 'discount' => 0.5]);
        Cache::flush();

        $row = $this->asDevice($this->seller())->getJson('/api/v1/products')->assertOk()->json('data.0');

        $this->assertSame(141588, $row['retail']['amount']);
        $this->assertSame('TMT', $row['retail']['currency']);
        $this->assertSame(70794, $row['final']['amount']);
    }

    /**
     * The field policy decides which columns a role receives, which means nothing
     * unless the caller is known. An anonymous request must not get the catalogue.
     */
    public function test_the_catalogue_is_not_public(): void
    {
        Product::factory()->count(3)->create();

        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_the_page_size_is_capped(): void
    {
        Product::factory()->count(5)->create();

        $this->asDevice($this->seller())->getJson('/api/v1/products?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }
}
