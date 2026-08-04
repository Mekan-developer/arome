<?php

namespace App\Repositories;

use App\Enums\AuditKind;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Collection;

class AuditRepository
{
    /**
     * Журнал за последние 24 месяца, отфильтрованный по типу события. Фильтрация —
     * SQL: записей за два года набирается много больше, чем стоит слать в браузер.
     *
     * @return Collection<int, AuditLog>
     */
    public function page(string $filter, int $limit = 200): Collection
    {
        $kinds = AuditKind::filters()[$filter] ?? [];

        return AuditLog::query()
            ->when($kinds !== [], fn ($query) => $query->whereIn('kind', $kinds))
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function record(AuditLog $entry): AuditLog
    {
        $entry->save();

        return $entry;
    }
}
