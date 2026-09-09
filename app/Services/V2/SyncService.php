<?php

namespace App\Services\V2;

use App\Models\User;
use App\Repositories\V2\DeviceRepository;
use App\Services\AuditService;
use App\Services\CatalogVersionService;
use App\Services\V2\Data\DeviceHeartbeat;
use App\Services\V2\Data\SyncResult;
use App\Services\V2\Exceptions\DeviceBlockedException;

/**
 * Синхронизация телефона с сервером: устройство сообщает применённую ревизию каталога
 * и узнаёт, насколько отстало.
 *
 * Регистрация аппарата — часть этого же вызова: до первой синхронизации сервер знает
 * о телефоне только то, что он получил токен.
 */
class SyncService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly CatalogVersionService $catalogVersion,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws DeviceBlockedException
     */
    public function apply(User $user, DeviceHeartbeat $heartbeat): SyncResult
    {
        $device = $this->devices->registerForUser($user->id, $heartbeat->deviceName, $heartbeat->appVersion);

        if ($device->is_blocked) {
            throw new DeviceBlockedException;
        }

        $catalogVersion = $this->catalogVersion->current();
        $result = new SyncResult(
            catalogVersion: $catalogVersion,
            previousVersion: $heartbeat->sinceVersion,
            lag: max(0, $catalogVersion - $heartbeat->sinceVersion),
        );

        $this->devices->markSynced($device, $catalogVersion);

        // Догнавший телефон — рутина; в журнал попадает только полученная дельта.
        if ($result->lag > 0) {
            $this->audit->record(
                $user->name,
                'Синхронизация устройства',
                $device->model,
                (string) $result->previousVersion,
                (string) $result->catalogVersion,
                'auth',
            );
        }

        return $result;
    }
}
