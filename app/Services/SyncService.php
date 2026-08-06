<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use App\Repositories\DeviceRepository;

/**
 * Синхронизация устройства с сервером. Отдаёт номер актуальной ревизии и насколько
 * устройство от неё отстало — по этой цифре раздел «Синхронизация» и красит строки.
 */
class SyncService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AuditService $audit,
        private readonly CatalogVersionService $catalogVersion,
    ) {}

    /**
     * @return array{catalog_version: int, previous_version: int, lag: int, up_to_date: bool}
     */
    public function apply(User $user, ?Device $device, int $sinceVersion): array
    {
        $catalogVersion = $this->catalogVersion->current();
        $lag = max(0, $catalogVersion - $sinceVersion);

        if ($device !== null) {
            $this->devices->markSynced($device, $catalogVersion);
        }

        if ($lag > 0) {
            $this->audit->record(
                $user->name,
                'Синхронизация устройства',
                $device?->model ?? 'устройство',
                (string) $sinceVersion,
                (string) $catalogVersion,
                'auth',
            );
        }

        return [
            'catalog_version' => $catalogVersion,
            'previous_version' => $sinceVersion,
            'lag' => $lag,
            'up_to_date' => $lag === 0,
        ];
    }
}
