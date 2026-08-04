<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'point_id', 'model', 'app_version', 'synced_at', 'data_version', 'lag', 'is_blocked'])]
class Device extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'is_blocked' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Point, $this>
     */
    public function point(): BelongsTo
    {
        return $this->belongsTo(Point::class);
    }

    /**
     * Severity bucket driving the row colour: blocked, ok, warn or bad.
     */
    public function level(): string
    {
        if ($this->is_blocked) {
            return 'blocked';
        }

        if ($this->lag === 0) {
            return 'ok';
        }

        return $this->lag < 100 ? 'warn' : 'bad';
    }
}
