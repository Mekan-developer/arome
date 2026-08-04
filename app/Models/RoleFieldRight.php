<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role', 'field', 'visible'])]
class RoleFieldRight extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['visible' => 'boolean'];
    }
}
