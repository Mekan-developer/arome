<?php

namespace App\Services\V2\Data;

use App\Models\Product;
use Illuminate\Support\LazyCollection;

/**
 * Каталог целиком, читаемый пачками. Коллекция ленивая намеренно: прайс на десятки
 * тысяч строк не должен собираться в памяти ни здесь, ни в ресурсе.
 */
final class CatalogStream
{
    /**
     * @param  LazyCollection<int, Product>  $products
     */
    public function __construct(
        public readonly LazyCollection $products,
        public readonly FieldAccess $access,
    ) {}
}
