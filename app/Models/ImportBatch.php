<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['file_name', 'imported_at', 'rows_ok', 'rows_failed'])]
class ImportBatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }
}
