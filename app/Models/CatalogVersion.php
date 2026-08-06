<?php

namespace App\Models;

use App\Services\CatalogVersionService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Одна строка на всю установку: счётчик правок каталога. Растёт через
 * {@see CatalogVersionService}, напрямую его никто не пишет.
 */
#[Fillable(['number'])]
class CatalogVersion extends Model {}
