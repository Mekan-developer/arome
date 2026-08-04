<?php

namespace App\Services;

use App\Models\AuditLog;

/**
 * The action log is append-only: rows are never edited or deleted, and the flag in §4
 * only hides the section — recording keeps happening either way.
 */
class AuditService
{
    /**
     * Event kind => colour token used for the underline in the journal.
     *
     * @var array<string, string>
     */
    public const KIND_COLORS = [
        'price' => 'var(--brass-dark)',
        'stock' => 'var(--ink-2)',
        'rights' => 'var(--danger)',
        'user' => 'var(--danger)',
        'import' => 'var(--ok)',
        'product' => 'var(--ink-2)',
        'auth' => 'var(--ink-3)',
        'point' => 'var(--ok)',
    ];

    /**
     * Filter options for the "Тип события" select.
     *
     * @var array<string, list<string>>
     */
    public const FILTERS = [
        'all' => [],
        'price' => ['price'],
        'rights' => ['rights'],
        'users' => ['user'],
        'products' => ['product'],
    ];

    public function record(string $actor, string $action, ?string $object = null, ?string $from = null, ?string $to = null, string $kind = 'product'): AuditLog
    {
        return AuditLog::create([
            'happened_at' => now(),
            'actor' => $actor,
            'action' => $action,
            'object' => $object,
            'value_from' => $from,
            'value_to' => $to,
            'kind' => $kind,
        ]);
    }
}
