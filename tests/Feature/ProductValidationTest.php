<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The five rules of §8.6. The wording is part of the design, so the tests assert the
 * exact sentences the operator is shown.
 */
class ProductValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->admin = $this->admin();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProduct(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post('/products', array_merge([
            'name' => 'LATTAFA KHAMRAH EDP 100ML',
            'sku' => '512345',
            'barcode' => '8011003993802',
            'price' => 420,
            'discount' => 0,
            'status' => 'active',
        ], $overrides));
    }

    public function test_the_name_is_required(): void
    {
        $this->createProduct(['name' => ''])->assertSessionHasErrors([
            'name' => 'Не заполнена номенклатура — без названия продавец не поймёт, что за товар.',
        ]);
    }

    public function test_the_article_must_be_four_or_more_digits(): void
    {
        $message = 'Артикул должен быть числом из 4 и более цифр — по нему сопоставляется прайс при импорте.';

        $this->createProduct(['sku' => '123'])->assertSessionHasErrors(['sku' => $message]);
        $this->createProduct(['sku' => 'abcd'])->assertSessionHasErrors(['sku' => $message]);
    }

    public function test_the_article_must_be_unique(): void
    {
        Product::factory()->create(['sku' => '512345']);

        $this->createProduct(['sku' => '512345'])->assertSessionHasErrors([
            'sku' => 'Артикул 512345 уже есть в каталоге.',
        ]);
    }

    public function test_a_barcode_of_any_length_is_accepted(): void
    {
        $this->createProduct(['sku' => '512001', 'barcode' => '801100399391'])->assertSessionHasNoErrors();
        $this->createProduct(['sku' => '512002', 'barcode' => '80110039938021'])->assertSessionHasNoErrors();
        $this->createProduct(['sku' => '512003', 'barcode' => 'ABC-7'])->assertSessionHasNoErrors();
    }

    public function test_the_barcode_is_required(): void
    {
        $this->createProduct(['barcode' => ''])->assertSessionHasErrors([
            'barcode' => 'Не заполнен штрихкод — без него сканер в зале не найдёт товар.',
        ]);
    }

    public function test_the_barcode_stops_at_sixty_four_characters(): void
    {
        $this->createProduct(['barcode' => str_repeat('8', 65)])->assertSessionHasErrors([
            'barcode' => 'Штрихкод не длиннее 64 символов.',
        ]);
    }

    public function test_the_barcode_must_be_unique(): void
    {
        Product::factory()->create(['barcode' => '8011003993802']);

        $this->createProduct(['barcode' => '8011003993802'])->assertSessionHasErrors([
            'barcode' => 'Штрихкод 8011003993802 уже занят другим товаром.',
        ]);
    }

    public function test_the_price_must_be_above_zero(): void
    {
        $message = 'Розничная цена должна быть больше нуля.';

        $this->createProduct(['price' => 0])->assertSessionHasErrors(['price' => $message]);
        $this->createProduct(['price' => -10])->assertSessionHasErrors(['price' => $message]);
    }

    public function test_the_discount_runs_from_zero_to_ninety_percent(): void
    {
        $message = 'Скидка допустима от 0 до 90 %.';

        $this->createProduct(['discount' => 91])->assertSessionHasErrors(['discount' => $message]);
        $this->createProduct(['discount' => -1])->assertSessionHasErrors(['discount' => $message]);
    }

    public function test_a_valid_product_is_stored_with_the_discount_as_a_fraction(): void
    {
        $this->createProduct(['discount' => 50])->assertSessionHasNoErrors();

        $product = Product::firstWhere('sku', '512345');

        $this->assertNotNull($product);
        $this->assertSame('0.5000', $product->discount);
        $this->assertSame(210.0, $product->finalPrice());
        $this->assertStringStartsWith('AA', $product->main_code);
    }

    public function test_a_seller_may_not_create_a_product(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);

        $this->actingAs($seller)->post('/products', [
            'name' => 'X',
            'sku' => '512345',
            'barcode' => '8011003993802',
            'price' => 420,
            'discount' => 0,
            'status' => 'active',
        ])->assertForbidden();
    }
}
