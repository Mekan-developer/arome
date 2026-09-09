<?php

namespace App\Services\V2\Data;

use App\Models\Product;

/**
 * Одна карточка товара с правами роли — ответ на поднесённый к сканеру штрихкод.
 */
final class CatalogCard
{
    public function __construct(
        public readonly Product $product,
        public readonly FieldAccess $access,
    ) {}
}
