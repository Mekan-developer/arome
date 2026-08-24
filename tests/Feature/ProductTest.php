<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Point;
use App\Models\Product;
use App\Repositories\ProductRepository;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->products = app(ProductRepository::class);
    }

    public function test_it_filters_by_status(): void
    {
        Product::factory()->count(3)->create();
        Product::factory()->hidden()->count(2)->create();

        $this->assertCount(3, $this->products->paginate(['status' => 'active'], 25, false)->items());
        $this->assertCount(2, $this->products->paginate(['status' => 'hidden'], 25, false)->items());
        $this->assertCount(5, $this->products->paginate(['status' => 'all'], 25, false)->items());
    }

    public function test_it_searches_by_name_article_and_barcode(): void
    {
        $needle = Product::factory()->create([
            'name' => 'LATTAFA KHAMRAH EDP 100ML',
            'sku' => '777001',
            'barcode' => '8011003993802',
        ]);
        Product::factory()->create(['name' => 'HUGO BOSS BOTTLED EDT 50ML']);

        foreach (['KHAMRAH', 'khamrah', '777001', '8011003993802'] as $term) {
            $found = $this->products->paginate(['q' => $term], 25, false)->items();

            $this->assertCount(1, $found, "Search for «{$term}» should find exactly one row.");
            $this->assertSame($needle->id, $found[0]->id);
        }
    }

    public function test_a_one_character_search_is_ignored(): void
    {
        Product::factory()->count(3)->create();

        $this->assertCount(3, $this->products->paginate(['q' => 'a'], 25, false)->items());
    }

    #[DataProvider('sorts')]
    public function test_it_sorts_by_the_allowed_columns(string $sort, string $column, bool $descending): void
    {
        Product::factory()->create(['name' => 'BBB', 'sku' => '510020', 'price' => 200]);
        Product::factory()->create(['name' => 'AAA', 'sku' => '510030', 'price' => 900]);
        Product::factory()->create(['name' => 'CCC', 'sku' => '510010', 'price' => 500]);

        $values = collect($this->products->paginate(['sort' => $sort], 25, false)->items())
            ->pluck($column)
            ->all();

        $expected = collect($values)->sort()->values()->all();

        $this->assertSame($descending ? array_reverse($expected) : $expected, $values);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function sorts(): array
    {
        return [
            'name ascending' => ['name', 'name', false],
            'name descending' => ['-name', 'name', true],
            'article ascending' => ['sku', 'sku', false],
            'price ascending' => ['price', 'price', false],
            'price descending' => ['-price', 'price', true],
            'wholesale ascending' => ['wholesale', 'wholesale_price', false],
            'wholesale descending' => ['-wholesale', 'wholesale_price', true],
        ];
    }

    public function test_an_unknown_sort_key_falls_back_to_the_name(): void
    {
        Product::factory()->create(['name' => 'BBB']);
        Product::factory()->create(['name' => 'AAA']);

        $items = $this->products->paginate(['sort' => 'price); drop table products;--'], 25, false)->items();

        $this->assertSame(['AAA', 'BBB'], collect($items)->pluck('name')->all());
    }

    public function test_it_pages_by_twenty_five(): void
    {
        Product::factory()->count(60)->create();

        $page = $this->products->paginate([], config('aroma.per_page'), false);

        $this->assertSame(25, $page->perPage());
        $this->assertCount(25, $page->items());
        $this->assertSame(3, $page->lastPage());
        $this->assertSame(60, $page->total());
    }

    #[DataProvider('prices')]
    public function test_it_computes_the_discounted_price(float $price, float $discount, float $expected): void
    {
        $this->assertSame($expected, ProductService::finalPrice($price, $discount));
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    public static function prices(): array
    {
        return [
            'no discount' => [1415.88, 0.0, 1415.88],
            'half off' => [1515.24, 0.5, 757.62],
            'fifteen percent' => [1894.64, 0.15, 1610.44],
            'rounds to the kopek' => [999.99, 0.3, 699.99],
            'rounds a third down' => [100.0, 0.3333, 66.67],
        ];
    }

    public function test_the_index_returns_rows_and_the_query_line(): void
    {
        Product::factory()->count(3)->create();

        $this->actingAs($this->admin())
            ->get('/products?sort=-price&status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Index')
                ->has('products.data', 3)
                ->where('queryString', 'GET /api/v1/products?q=&point=all&status=active&sort=-price&page=1'));
    }

    /**
     * The status is cast to a ProductStatus, and AuditService only takes strings — saving
     * a status change used to blow up on the way into the journal.
     */
    public function test_saving_a_status_change_writes_a_readable_journal_entry(): void
    {
        $product = Product::factory()->create(['status' => 'active']);

        $this->actingAs($this->admin())
            ->put('/products/'.$product->id, [
                'name' => $product->name,
                'main_code' => $product->main_code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'discount' => 0,
                'status' => 'hidden',
            ])
            ->assertRedirect();

        $this->assertSame(ProductStatus::Hidden, $product->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Статус товара изменён',
            'value_from' => 'в продаже',
            'value_to' => 'скрыт',
            'kind' => 'product',
        ]);
    }

    public function test_an_unchanged_status_writes_no_journal_entry(): void
    {
        $product = Product::factory()->create(['status' => 'active', 'discount' => 0]);

        $this->actingAs($this->admin())
            ->put('/products/'.$product->id, [
                'name' => 'ДРУГОЕ НАЗВАНИЕ',
                'main_code' => $product->main_code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'discount' => 0,
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'Статус товара изменён']);
    }

    /**
     * The modal reads card.name and card.price straight off the prop, so the card must
     * arrive flat — not wrapped in the «data» key a JsonResource adds.
     */
    public function test_the_card_prop_carries_the_fields_of_the_requested_product(): void
    {
        $product = Product::factory()->create(['name' => 'LATTAFA KHAMRAH EDP 100ML', 'price' => 1200, 'discount' => 0.15]);
        Product::factory()->count(2)->create();

        $this->actingAs($this->admin())
            ->get('/products?product='.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('card.id', $product->id)
                ->where('card.name', 'LATTAFA KHAMRAH EDP 100ML')
                ->where('card.price', fn ($price): bool => (float) $price === 1200.0)
                ->where('card.discountPercent', fn ($percent): bool => (float) $percent === 15.0)
                ->where('card.final', fn ($final): bool => (float) $final === 1020.0)
                ->missing('card.data'));
    }

    /**
     * Stock is not edited from the product card any more: it belongs to the warehouse and
     * to device sync. A quantity posted with the form must not reach the stock table.
     */
    public function test_the_card_carries_no_stock_and_a_posted_quantity_is_ignored(): void
    {
        $this->seedModules(['points' => true, 'productPoints' => true]);
        $point = Point::factory()->create();
        $product = Product::factory()->create(['discount' => 0]);
        $product->stocks()->create(['point_id' => $point->id, 'qty' => 7]);

        $this->actingAs($this->admin())
            ->get('/products?product='.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('card.stocks')->missing('card.totalStock'));

        $this->actingAs($this->admin())
            ->put('/products/'.$product->id, [
                'name' => $product->name,
                'main_code' => $product->main_code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'discount' => 0,
                'status' => 'active',
                'stocks' => [$point->id => 999],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('product_stocks', ['product_id' => $product->id, 'qty' => 7]);
    }

    public function test_the_archived_status_is_rejected(): void
    {
        $product = Product::factory()->create(['discount' => 0]);

        $this->actingAs($this->admin())
            ->put('/products/'.$product->id, [
                'name' => $product->name,
                'main_code' => $product->main_code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'discount' => 0,
                'status' => 'archived',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(ProductStatus::Active, $product->fresh()->status);
    }

    public function test_the_card_prop_is_absent_without_a_product_in_the_query(): void
    {
        Product::factory()->create();

        $this->actingAs($this->admin())
            ->get('/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('card', null));
    }

    /**
     * A list page must not fan out into a query per row.
     */
    public function test_the_index_does_not_run_a_query_per_row(): void
    {
        Product::factory()->count(30)->create();
        $admin = $this->admin();

        \DB::enableQueryLog();
        $this->actingAs($admin)->get('/products')->assertOk();

        $this->assertLessThan(12, count(\DB::getQueryLog()));
    }
}
