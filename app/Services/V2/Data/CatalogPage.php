<?php

namespace App\Services\V2\Data;

use App\Models\Product;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * Страница каталога вместе с правами, по которым её надо показать.
 */
final class CatalogPage
{
    /**
     * @param  Paginator<int, Product>  $products
     */
    public function __construct(
        public readonly Paginator $products,
        public readonly FieldAccess $access,
    ) {}
}
