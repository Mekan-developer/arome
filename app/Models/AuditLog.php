<?php

namespace App\Models;

use App\Enums\AuditKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['happened_at', 'actor', 'action', 'object', 'value_from', 'value_to', 'kind'])]
class AuditLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
            'kind' => AuditKind::class,
        ];
    }
}
