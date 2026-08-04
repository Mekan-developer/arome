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
    ) {}

    /**
     * Retail price after discount, rounded to the kopek.
     */
    public static function finalPrice(float $price, float $discount): float
    {
        return round($price * (1 - $discount), 2);
    }

    /**
     * Complete a barcode body to a valid EAN-13.
     */
    public static function ean13(string $digits): string
    {
        return CatalogGenerator::ean13($digits);
    }

    /**
     * A freshly generated, unused EAN-13 for the "Сгенерировать" button.
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
     * @param  array{name: string, sku: string, barcode: string, price: float, discount: float, status: string, kind?: string}  $data
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
                'discount' => $data['discount'],
                'status' => $data['status'],
            ]);

            $this->audit->record($actor, 'Создан товар', $product->main_code.' · '.$product->name, null, number_format((float) $product->price, 2, ',', ' '), 'product');

            return $product;
        });
    }

    /**
     * @param  array{name: string, main_code: string, sku: string, barcode: string, price: float, discount: float, status: string}  $data
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

            return $product;
        });
    }

    /**
     * One validated row of a price list, written into the catalogue. Matched and called
     * by {@see ImportService}, which already knows — from one batched lookup covering the
     * whole file — whether this sku exists; not wrapped in its own transaction, the
     * caller commits the batch as one unit.
     *
     * Status is deliberately left untouched on update: a product an administrator hid
     * from sale must not silently reappear just because its sku is still in the price
     * list. Sku itself never changes here either — it is the match key that produced
     * $existing in the first place.
     *
     * @param  array{mainCode: string, sku: string, barcode: string, name: string, price: float, discount: float}  $row
     * @return 'created'|'updated'
     */
    public function upsertFromImport(array $row, ?Product $existing, string $actor): string
    {
        if ($existing instanceof Product) {
            $priceBefore = (float) $existing->price;

            $existing->update([
                'main_code' => $row['mainCode'] !== '' ? $row['mainCode'] : $existing->main_code,
                'barcode' => $row['barcode'],
                'name' => $row['name'],
                'price' => $row['price'],
                'discount' => $row['discount'],
            ]);

            if ($priceBefore !== (float) $existing->price) {
                $existing->priceHistories()->create([
                    'changed_at' => now(),
                    'author' => $actor,
                    'reason' => 'импорт',
                    'price_from' => $priceBefore,
                    'price_to' => $existing->price,
                ]);
            }

            return 'updated';
        }

        Product::create([
            'main_code' => $row['mainCode'] !== '' ? $row['mainCode'] : $this->products->nextMainCode(),
            'sku' => $row['sku'],
            'barcode' => $row['barcode'],
            'name' => $row['name'],
            'kind' => 'EDT',
            'price' => $row['price'],
            'discount' => $row['discount'],
            'status' => ProductStatus::Active->value,
        ]);

        return 'created';
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

            foreach ($products as $product) {
                $priceBefore = (float) $product->price;

                match ($mode) {
                    'discount' => $product->discount = max(0, min(0.9, $value / 100)),
                    'percent' => $product->price = max(0, round($priceBefore * (1 + $value / 100), 2)),
                    'amount' => $product->price = max(0, round($priceBefore + $value, 2)),
                    'fixed' => $product->price = max(0, round($value, 2)),
                    default => null,
                };

                $product->save();

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

            return $products->count();
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

            return [
                'name' => $product->name,
                'from' => self::finalPrice($price, $discount),
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
