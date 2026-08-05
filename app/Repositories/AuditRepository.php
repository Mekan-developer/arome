<?php

namespace App\Repositories;

use App\Enums\AuditKind;
use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuditRepository
{
    /**
     * Журнал за последние 24 месяца, отфильтрованный по типу события. Фильтрация и
     * постраничность — SQL: записей за два года набирается много больше, чем стоит
     * слать в браузер.
     *
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function page(string $filter, int $perPage): LengthAwarePaginator
    {
        $kinds = AuditKind::filters()[$filter] ?? [];

        return AuditLog::query()
            ->when($kinds !== [], fn ($query) => $query->whereIn('kind', $kinds))
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function record(AuditLog $entry): AuditLog
    {
        $entry->save();

        return $entry;
    }
}
