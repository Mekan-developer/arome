<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\V2\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Веб-версия мобильного приложения продавца: тот же {@see CatalogService},
 * что и у API, только транспорт — сессия, не токен. Здесь проверяется именно граница
 * (кто куда попадает), а не сам каталог — его правила уже проверены у v2 API.
 */
class SellerSearchTest extends TestCase
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

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/search')->assertRedirect('/login');
    }

    public function test_a_seller_reaches_the_search_page(): void
    {
        $this->actingAs($this->seller())
            ->get('/search')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SellerSearch'));
    }

    public function test_staff_is_sent_back_to_the_panel(): void
    {
        $this->actingAs($this->admin())
            ->get('/search')
            ->assertRedirect('/products');
    }

    public function test_a_seller_finds_a_product_by_name(): void
    {
        Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML']);
        Product::factory()->create(['name' => 'VERSACE BRIGHT CRYSTAL EDT 30ML']);

        $rows = $this->actingAs($this->seller())
            ->getJson('/search/products?q=KHAMRAH')
            ->assertOk()
            ->json('data');

        $this->assertSame(['LATTAFA KHAMRAH EDP 100ML'], array_column($rows, 'name'));
    }

    public function test_a_seller_finds_a_product_by_barcode(): void
    {
        Product::factory()->create(['barcode' => '8011003993802', 'name' => 'LATTAFA KHAMRAH EDP 100ML']);

        $this->actingAs($this->seller())
            ->getJson('/search/barcode/8011003993802')
            ->assertOk()
            ->assertJsonPath('data.name', 'LATTAFA KHAMRAH EDP 100ML');
    }

    public function test_an_unknown_barcode_answers_not_found(): void
    {
        $this->actingAs($this->seller())
            ->getJson('/search/barcode/0000000000000')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'product_not_found');
    }

    public function test_staff_cannot_reach_the_seller_search_endpoints(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/search/products?q=xx')
            ->assertRedirect('/products');
    }
}
