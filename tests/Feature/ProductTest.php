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

    /**
     * Размер страницы задаётся в aroma.per_page, а не зашит в репозиторий, поэтому
     * ассерты считаются от конфига: смена значения не должна ронять тест.
     */
    public function test_it_pages_by_the_configured_size(): void
    {
        $perPage = (int) config('aroma.per_page');

        Product::factory()->count(60)->create();

        $page = $this->products->paginate([], $perPage, false);

        $this->assertSame($perPage, $page->perPage());
        $this->assertCount($perPage, $page->items());
        $this->assertSame((int) ceil(60 / $perPage), $page->lastPage());
        $this->assertSame(60, $page->total());
    }

    #[DataProvider('prices')]
    public function test_it_computes_the_discounted_price(float $price, float $discount, float $expected): void
    {
        $this->assertSame($expected, ProductService::finalPrice($price, $discount));
    }

    /**
     * Копеек в панели нет: цена со скидкой округляется до целого — от 0,5 и выше
     * вверх, ниже вниз.
     *
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    public static function prices(): array
    {
        return [
            'no discount' => [1415.88, 0.0, 1416.0],
            'half off' => [1515.24, 0.5, 758.0],
            'fifteen percent' => [1894.64, 0.15, 1610.0],
            'rounds up from a half' => [999.99, 0.3, 700.0],
            'rounds a third down' => [100.0, 0.3333, 67.0],
        ];
    }

    public function test_the_index_returns_rows(): void
    {
        Product::factory()->count(3)->create();

        $this->actingAs($this->admin())
            ->get('/products?sort=-price&status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Index')
                ->has('products.data', 3));
    }

    /**
     * Размер страницы выбирается в подвале таблицы и живёт в адресе — отфильтрованный
     * список с сотней строк должен пережить перезагрузку и уехать ссылкой коллеге.
     */
    public function test_the_page_size_comes_from_the_url(): void
    {
        Product::factory()->count(60)->create();

        $this->actingAs($this->admin())
            ->get('/products?per_page=50')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 50)
                ->where('filters.per_page', 50)
                ->where('products.meta.last_page', 2)
                ->where('perPageOptions', [15, 30, 50, 100]));
    }

    /**
     * Всё, чего нет в списке размеров, — это `?per_page=100000`: каталог целиком в память
     * и панель на коленях. Такой запрос отвечает страницей по умолчанию.
     */
    #[DataProvider('rejectedPageSizes')]
    public function test_a_page_size_outside_the_list_falls_back_to_the_default(string $value): void
    {
        Product::factory()->count(20)->create();

        $this->actingAs($this->admin())
            ->get('/products?per_page='.$value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 15)
                ->where('filters.per_page', 15));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedPageSizes(): array
    {
        return [
            'the whole catalogue' => ['100000'],
            'not on the list' => ['25'],
            'zero' => ['0'],
            'negative' => ['-10'],
            'not a number' => ['все'],
        ];
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

    /**
     * Цена со скидкой из прайса держится, только пока её никто не переспорил руками:
     * в форме карточки скидка задаётся процентом, и оставленная цена из файла молча
     * перебивала бы его — администратор сохранил бы «10 %» и не увидел перемены.
     */
    public function test_saving_the_card_drops_a_discount_price_named_by_the_price_list(): void
    {
        $product = Product::factory()->create([
            'price' => 130,
            'discount' => 0,
            'discount_price' => 120,
        ]);

        $this->actingAs($this->admin())
            ->put('/products/'.$product->id, [
                'name' => $product->name,
                'main_code' => $product->main_code,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => 130,
                'discount' => 10,
                'status' => 'active',
            ])
            ->assertRedirect();

        $product->refresh();

        $this->assertNull($product->discount_price);
        $this->assertSame(117.0, $product->finalPrice());
    }

    /**
     * Массовая правка цен по той же причине снимает цену из прайса: иначе «поднять
     * цены на 10 %» не сдвинуло бы у такого товара ровным счётом ничего.
     */
    public function test_a_bulk_price_edit_drops_a_discount_price_named_by_the_price_list(): void
    {
        $product = Product::factory()->create([
            'price' => 130,
            'discount' => 0,
            'discount_price' => 120,
        ]);

        $this->actingAs($this->admin())
            ->post('/products/bulk', ['ids' => [$product->id], 'mode' => 'percent', 'value' => 10])
            ->assertRedirect();

        $product->refresh();

        $this->assertNull($product->discount_price);
        $this->assertSame(143.0, $product->finalPrice());
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
