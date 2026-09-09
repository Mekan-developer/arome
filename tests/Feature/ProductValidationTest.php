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

    /**
     * Артикул у поставщика бывает какой угодно, и карточку, приехавшую из прайса, надо
     * уметь открыть и сохранить — форма его формат больше не навязывает.
     */
    public function test_an_article_of_any_shape_is_accepted(): void
    {
        $this->createProduct(['sku' => '123'])->assertSessionHasNoErrors();
        $this->createProduct(['sku' => 'AR-7001'])->assertSessionHasNoErrors();
    }

    /**
     * Артикул необязателен и не уникален: прайс задаёт каталог как есть, а в нём одна и
     * та же позиция встречается дважды и бывает вовсе без артикула.
     */
    public function test_the_article_may_be_empty_or_repeated(): void
    {
        Product::factory()->create(['sku' => '512345']);

        $this->createProduct(['sku' => '512345'])->assertSessionHasNoErrors();
        $this->createProduct(['sku' => ''])->assertSessionHasNoErrors();

        $this->assertSame(2, Product::where('sku', '512345')->count());
        $this->assertSame(1, Product::whereNull('sku')->count());
    }

    public function test_a_barcode_of_any_length_is_accepted(): void
    {
        $this->createProduct(['sku' => '512001', 'barcode' => '801100399391'])->assertSessionHasNoErrors();
        $this->createProduct(['sku' => '512002', 'barcode' => '80110039938021'])->assertSessionHasNoErrors();
        $this->createProduct(['sku' => '512003', 'barcode' => 'ABC-7'])->assertSessionHasNoErrors();
    }

    /**
     * Штрихкод необязателен: прайс приходит и без него, и такую карточку потом надо
     * уметь открыть и сохранить, ничего не выдумывая. В базу пустое поле уходит как
     * NULL — пустых строк уникальный индекс пустил бы только одну.
     */
    public function test_the_barcode_may_be_left_empty(): void
    {
        $this->createProduct(['sku' => '512004', 'barcode' => ''])->assertSessionHasNoErrors();
        $this->createProduct(['sku' => '512005', 'barcode' => ''])->assertSessionHasNoErrors();

        $this->assertSame(2, Product::whereNull('barcode')->count());
    }

    public function test_the_barcode_stops_at_sixty_four_characters(): void
    {
        $this->createProduct(['barcode' => str_repeat('8', 65)])->assertSessionHasErrors([
            'barcode' => 'Штрихкод не длиннее 64 символов.',
        ]);
    }

    /**
     * Штрихкод больше не уникален: прайс дублирует позицию вместе с ним, и обе карточки
     * сохраняются как есть.
     */
    public function test_the_barcode_may_be_repeated(): void
    {
        Product::factory()->create(['barcode' => '8011003993802']);

        $this->createProduct(['barcode' => '8011003993802'])->assertSessionHasNoErrors();

        $this->assertSame(2, Product::where('barcode', '8011003993802')->count());
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

    /**
     * Оптовая цена, как и розничная, округляется до целого при ручном создании товара —
     * тем же правилом, что и при импорте прайса, см. ImportService::roundPrice().
     */
    public function test_the_wholesale_price_is_optional_and_rounded_to_the_nearest_whole_number(): void
    {
        $this->createProduct(['sku' => '512100', 'barcode' => '8011003993901', 'wholesale_price' => 280.5])
            ->assertSessionHasNoErrors();

        $this->assertSame('281.00', Product::firstWhere('sku', '512100')?->wholesale_price);

        $this->createProduct(['sku' => '512101', 'barcode' => '8011003993902'])->assertSessionHasNoErrors();

        $this->assertNull(Product::firstWhere('sku', '512101')?->wholesale_price);
    }

    public function test_the_wholesale_price_may_not_be_negative(): void
    {
        $this->createProduct(['wholesale_price' => -1])->assertSessionHasErrors([
            'wholesale_price' => 'Оптовая цена не может быть отрицательной.',
        ]);
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
