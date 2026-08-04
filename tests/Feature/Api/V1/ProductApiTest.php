<?php

namespace Tests\Feature\Api\V1;

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
