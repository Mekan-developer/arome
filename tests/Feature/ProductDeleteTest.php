<?php

namespace Tests\Feature;

use App\Models\Point;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Удаление товара из каталога. Действие необратимое, поэтому проверяется и то, что оно
 * доходит до базы, и то, что до неё не доходит ни у кого, кроме администратора.
 */
class ProductDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    public function test_an_administrator_deletes_a_product(): void
    {
        $product = Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML']);
        Product::factory()->count(2)->create();

        /*
         * Запрос идёт с заголовком Inertia — так его шлёт панель. Ответ обязан быть 303,
         * а не 302: на 302 браузер повторит DELETE уже по адресу списка.
         */
        $this->actingAs($this->admin())
            ->withHeader('X-Inertia', 'true')
            ->delete('/products/'.$product->id)
            ->assertStatus(303);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_the_deletion_is_written_into_the_journal(): void
    {
        $product = Product::factory()->create([
            'main_code' => 'AA1042',
            'name' => 'LATTAFA KHAMRAH EDP 100ML',
            'price' => 1200,
        ]);

        $this->actingAs($this->admin())->delete('/products/'.$product->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Удалён товар',
            'object' => 'AA1042 · LATTAFA KHAMRAH EDP 100ML',
            'value_from' => '1 200,00',
            'value_to' => null,
            'kind' => 'product',
        ]);
    }

    /**
     * Телефон узнаёт об исчезнувшей карточке единственным способом — по номеру ревизии.
     */
    public function test_the_deletion_publishes_a_new_catalog_revision(): void
    {
        $product = Product::factory()->create();
        $before = app(CatalogVersionService::class)->current();

        $this->actingAs($this->admin())->delete('/products/'.$product->id);

        $this->assertSame($before + 1, app(CatalogVersionService::class)->current());
    }

    /**
     * Остатки и история цены висят на товаре внешними ключами и уезжают вместе с ним —
     * иначе в базе остаются строки, ссылающиеся в пустоту.
     */
    public function test_the_stock_and_the_price_history_go_with_the_product(): void
    {
        $this->seedModules(['points' => true, 'productPoints' => true]);
        $point = Point::factory()->create();
        $product = Product::factory()->create();
        $product->stocks()->create(['point_id' => $point->id, 'qty' => 7]);
        $product->priceHistories()->create([
            'changed_at' => now(),
            'author' => 'Айнур Д.',
            'reason' => 'вручную',
            'price_from' => 100,
            'price_to' => 120,
        ]);

        $this->actingAs($this->admin())->delete('/products/'.$product->id);

        $this->assertDatabaseMissing('product_stocks', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('price_histories', ['product_id' => $product->id]);
    }

    public function test_a_seller_cannot_delete_a_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'seller']))
            ->delete('/products/'.$product->id)
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_a_guest_cannot_delete_a_product(): void
    {
        $product = Product::factory()->create();

        $this->delete('/products/'.$product->id)->assertRedirect('/login');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_deleting_a_product_that_is_already_gone_gives_a_404(): void
    {
        $this->actingAs($this->admin())
            ->delete('/products/999999')
            ->assertNotFound();
    }

    /**
     * Ссылка на карточку живёт дольше самой карточки — открытая после удаления, она
     * обязана показать каталог без модального окна, а не уронить страницу.
     */
    public function test_the_list_still_opens_with_a_stale_product_in_the_query(): void
    {
        $product = Product::factory()->create();
        $id = $product->id;

        $this->actingAs($this->admin())->delete('/products/'.$id);

        $this->actingAs($this->admin())
            ->get('/products?product='.$id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/Index')->where('card', null));
    }
}
