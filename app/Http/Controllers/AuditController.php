<?php

namespace App\Http\Controllers;

use App\Enums\AuditKind;
use App\Models\AuditLog;
use App\Repositories\AuditRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function __construct(private readonly AuditRepository $audit) {}

    public function index(Request $request): Response
    {
        $filter = (string) $request->query('kind', 'all');
        $filter = array_key_exists($filter, AuditKind::filters()) ? $filter : 'all';

        return Inertia::render('Audit/Index', [
            'filter' => $filter,
            /*
             * through() вместо map(): он переписывает только строки страницы и
             * оставляет счётчики пагинатора на месте — футеру нужны current_page и total.
             */
            'entries' => fn () => $this->audit->page($filter, config('aroma.per_page'))
                ->through(fn (AuditLog $entry): array => [
                    'id' => $entry->id,
                    'time' => $entry->happened_at->format('d.m.Y H:i'),
                    'actor' => $entry->actor,
                    'action' => $entry->action,
                    'object' => $entry->object,
                    'from' => $entry->value_from,
                    'to' => $entry->value_to,
                    'kind' => $entry->kind->value,
                    'kindLabel' => $entry->kind->label(),
                    'kindColor' => $entry->kind->color(),
                ]),
        ]);
    }
}
