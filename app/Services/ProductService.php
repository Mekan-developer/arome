<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Repositories\ProductRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProductService
{
    /**
     * Colour of the category stripe down the left edge of a product row.
     *
     * @var array<string, string>
     */
    public const KIND_COLORS = [
        'PARFUM' => 'var(--c-parfum)',
        'EDP' => 'var(--c-edp)',
        'EDT' => 'var(--c-edt)',
        'CARE' => 'var(--c-edc)',
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly AuditService $audit,
        private readonly CatalogVersionService $catalogVersion,
    ) {}

    /**
     * Цена, которую продавец называет покупателю, — одно правило на всю панель.
     *
     * Обычно это розничная минус процент скидки. Но прайс поставщика умеет назвать
     * цену со скидкой напрямую, не объявляя процента, — такая цена и есть продажная,
     * пересчитывать её не из чего, см. {@see ImportService}. Процент при этом остаётся
     * главным: цена со скидкой попадает в карточку, только когда процента в файле нет.
     *
     * Копеек в панели нет: цена со скидкой округляется до целого тем же правилом, что
     * и цены прайса, — от 0,5 и выше вверх, ниже вниз.
     */
    public static function finalPrice(float $price, float $discount, ?float $discountPrice = null): float
    {
        return $discountPrice ?? round($price * (1 - $discount));
    }

    /**
     * Complete a barcode body to a valid EAN-13.
     */
    public static function ean13(string $digits): string
    {
        return CatalogGenerator::ean13($digits);
    }

    /**
     * A freshly generated, unused EAN-13 for the "Сгенерировать" button. Only ever one
     * at a time, by hand: the import does not generate anything, a price list without
     * barcodes leaves them empty.
     */
    public function generateBarcode(): string
    {
        do {
            $barcode = self::ean13('801100399'.random_int(100, 999));
        } while ($this->products->barcodeExists($barcode));

        return $barcode;
    }

    public function nextMainCode(): string
    {
        return $this->products->nextMainCode();
    }

    /**
     * @param  array{name: string, sku: string|null, barcode: string|null, price: float, wholesale_price?: float|null, discount: float, status: string, kind?: string}  $data
     */
    public function create(array $data, string $actor): Product
    {
        return DB::transaction(function () use ($data, $actor): Product {
            $product = Product::create([
                'main_code' => $this->products->nextMainCode(),
                'sku' => $data['sku'],
                'barcode' => $data['barcode'],
                'name' => $data['name'],
                'kind' => $data['kind'] ?? 'EDT',
                'price' => $data['price'],
                'wholesale_price' => $data['wholesale_price'] ?? null,
                'discount' => $data['discount'],
                'status' => $data['status'],
            ]);

            $this->audit->record($actor, 'Создан товар', $product->main_code.' · '.$product->name, null, number_format((float) $product->price, 2, ',', ' '), 'product');
            $this->catalogVersion->bump();

            return $product;
        });
    }

    /**
     * @param  array{name: string, main_code: string, sku: string|null, barcode: string|null, price: float, wholesale_price?: float|null, discount: float, status: string}  $data
     */
    public function update(Product $product, array $data, string $actor): Product
    {
        return DB::transaction(function () use ($product, $data, $actor): Product {
            $priceBefore = (float) $product->price;
            $statusBefore = $product->status;

            $product->update([
                'main_code' => $data['main_code'],
                'sku' => $data['sku'],
                'barcode' => $data['barcode'],
                'name' => $data['name'],
                'price' => $data['price'],
                'discount' => $data['discount'],
                'status' => $data['status'],
                /*
                 * Цена со скидкой из прайса живёт, только пока её никто не переспорил
                 * руками: в форме карточки скидка задаётся процентом, и оставленная
                 * цена из файла молча перебивала бы его — администратор сохранил бы
                 * «30 %» и не увидел никакой перемены, см. self::finalPrice().
                 *
                 * Ключ появляется, только когда есть что снимать: null поверх null
                 * Eloquent считает изменением, если колонки не было в выборке, и
                 * открытая-закрытая карточка поднимала бы ревизию каталога.
                 */
                ...($product->discount_price !== null ? ['discount_price' => null] : []),
                /* Ключа нет — оптовую цену не трогают: пустое поле формы приходит как null явно. */
                ...(array_key_exists('wholesale_price', $data) ? ['wholesale_price' => $data['wholesale_price']] : []),
            ]);

            if ($priceBefore !== (float) $product->price) {
                $product->priceHistories()->create([
                    'changed_at' => now(),
                    'author' => $actor,
                    'reason' => 'вручную',
                    'price_from' => $priceBefore,
                    'price_to' => $product->price,
                ]);

                $this->audit->record(
                    $actor,
                    'Изменена цена',
                    $product->main_code.' · '.$product->name,
                    number_format($priceBefore, 2, ',', ' '),
                    number_format((float) $product->price, 2, ',', ' '),
                    'price',
                );
            }

            if ($statusBefore !== $product->status) {
                $this->audit->record(
                    $actor,
                    'Статус товара изменён',
                    $product->main_code.' · '.$product->name,
                    self::statusCaption($statusBefore),
                    self::statusCaption($product->status),
                    'product',
                );
            }

            /* Открыли карточку и закрыли, ничего не тронув, — это не новая ревизия. */
            if ($product->wasChanged()) {
                $this->catalogVersion->bump();
            }

            return $product;
        });
    }

    /**
     * One validated row of a price list, written into the catalogue as a new card.
     * Called by {@see ImportService}, которое перед этим стёрло каталог целиком: прайс
     * задаёт каталог полностью, обновлять здесь нечего — каждая строка файла заводит
     * товар. Собственной транзакции нет: весь прайс коммитится одной, вызывающим.
     *
     * Ревизию каталога здесь не двигают: файл на 500 строк — одна публикация, поэтому
     * счётчик поднимает {@see ImportService}, один раз на всю загрузку.
     *
     * Пустой артикул, штрихкод и опт остаются пустыми: файл сохраняется как есть, за
     * поставщика ничего не придумывается. Статус у всех новых карточек «в продаже» —
     * скрытых товаров после замены каталога не остаётся, скрывать нужно заново.
     *
     * @param  array{mainCode: string, sku: string, barcode: string, name: string, price: float, discount: float, discountPrice?: float|null, wholesalePrice?: float|null}  $row
     */
    public function createFromImport(array $row): Product
    {
        return Product::create([
            'main_code' => $row['mainCode'] !== '' ? $row['mainCode'] : $this->products->nextMainCode(),
            'sku' => $row['sku'] !== '' ? $row['sku'] : null,
            'barcode' => $row['barcode'] !== '' ? $row['barcode'] : null,
            'name' => $row['name'],
            'kind' => 'EDT',
            'price' => $row['price'],
            'wholesale_price' => $row['wholesalePrice'] ?? null,
            'discount' => $row['discount'],
            'discount_price' => $row['discountPrice'] ?? null,
            'status' => ProductStatus::Active->value,
        ]);
    }

    /**
     * Bulk price / discount edit over a selection.
     *
     * @param  list<int>  $ids
     * @param  'discount'|'percent'|'amount'|'fixed'  $mode
     */
    public function applyBulk(array $ids, string $mode, float $value, string $actor): int
    {
        return DB::transaction(function () use ($ids, $mode, $value, $actor): int {
            $products = $this->products->whereIds($ids);
            $changed = 0;

            foreach ($products as $product) {
                $priceBefore = (float) $product->price;

                match ($mode) {
                    'discount' => $product->discount = max(0, min(0.9, $value / 100)),
                    'percent' => $product->price = max(0, round($priceBefore * (1 + $value / 100), 2)),
                    'amount' => $product->price = max(0, round($priceBefore + $value, 2)),
                    'fixed' => $product->price = max(0, round($value, 2)),
                    default => null,
                };

                /*
                 * Названная прайсом цена со скидкой держится только до первой правки
                 * руками: иначе она осталась бы продажной ценой поверх новой розничной,
                 * и «поднять цены на 10 %» не сдвинуло бы у такого товара ничего.
                 */
                $product->discount_price = null;

                $product->save();

                if ($product->wasChanged()) {
                    $changed++;
                }

                if ($priceBefore !== (float) $product->price) {
                    $product->priceHistories()->create([
                        'changed_at' => now(),
                        'author' => $actor,
                        'reason' => 'массовая правка',
                        'price_from' => $priceBefore,
                        'price_to' => $product->price,
                    ]);
                }
            }

            $this->audit->record($actor, 'Массовая правка цены', 'Выбрано товаров: '.$products->count(), null, $mode, 'price');

            /* Правка всей выборки — одна ревизия каталога, а не по одной на товар. */
            if ($changed > 0) {
                $this->catalogVersion->bump();
            }

            return $products->count();
        });
    }

    /**
     * Безвозвратное удаление карточки. Остатки по точкам, история цены и сканы уезжают
     * следом по внешним ключам — их не переносят и не восстанавливают.
     *
     * Запись в журнал делается до удаления: после него у модели уже нет ни кода, ни
     * названия, которые нужно в неё положить.
     */
    public function delete(Product $product, string $actor): void
    {
        DB::transaction(function () use ($product, $actor): void {
            $this->audit->record(
                $actor,
                'Удалён товар',
                $product->main_code.' · '.$product->name,
                number_format((float) $product->price, 2, ',', ' '),
                null,
                'product',
            );

            $product->delete();

            $this->catalogVersion->bump();
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function hide(array $ids, string $actor): int
    {
        return DB::transaction(function () use ($ids, $actor): int {
            /* A mass update goes straight to SQL and skips the model casts, so the enum has to be unwrapped by hand. */
            $count = Product::whereIn('id', $ids)->update(['status' => ProductStatus::Hidden->value]);

            $this->audit->record(
                $actor,
                'Товары скрыты',
                'Выбрано товаров: '.$count,
                self::statusCaption(ProductStatus::Active),
                self::statusCaption(ProductStatus::Hidden),
                'product',
            );

            if ($count > 0) {
                $this->catalogVersion->bump();
            }

            return $count;
        });
    }

    /**
     * Preview rows for the bulk modal: "было → стало" for the first three of a selection.
     *
     * @param  Collection<int, Product>  $products
     * @return list<array{name: string, from: float, to: float}>
     */
    public static function previewBulk(Collection $products, string $mode, float $value): array
    {
        return $products->take(3)->map(function (Product $product) use ($mode, $value): array {
            $price = (float) $product->price;
            $discount = (float) $product->discount;

            $to = match ($mode) {
                'discount' => self::finalPrice($price, max(0, min(0.9, $value / 100))),
                'percent' => self::finalPrice(round($price * (1 + $value / 100), 2), $discount),
                'amount' => self::finalPrice(round($price + $value, 2), $discount),
                'fixed' => self::finalPrice(round($value, 2), $discount),
                default => self::finalPrice($price, $discount),
            };

            /* «Стало» без цены со скидкой из прайса намеренно: правка её снимает,
             * см. self::applyBulk(). */
            return [
                'name' => $product->name,
                'from' => $product->finalPrice(),
                'to' => max(0, $to),
            ];
        })->values()->all();
    }

    /**
     * The journal is written in lowercase Russian throughout («активен», «заблокирован»),
     * so a status goes in as «в продаже», not as the SCREAMING label or the raw enum —
     * AuditService only accepts strings.
     */
    private static function statusCaption(ProductStatus $status): string
    {
        return mb_strtolower($status->label());
    }
}
