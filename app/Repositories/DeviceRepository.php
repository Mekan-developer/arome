<?php

namespace App\Repositories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Collection;

class DeviceRepository
{
    /**
     * Устройства для раздела «Синхронизация». Точка подгружается только когда модуль
     * точек включён — иначе колонки всё равно нет.
     *
     * @return Collection<int, Device>
     */
    public function all(bool $withPoints): Collection
    {
        return Device::query()
            ->with(['user:id,name', ...($withPoints ? ['point:id,code,name'] : [])])
            ->orderBy('id')
            ->get();
    }

    /**
     * Устройство сотрудника — по нему завершают сессию при блокировке.
     */
    public function forUser(int $userId): ?Device
    {
        return Device::where('user_id', $userId)->first();
    }

    /**
     * Отставание от версии каталога на сервере.
     */
    public function markSynced(Device $device, int $catalogVersion): Device
    {
        $device->update([
            'synced_at' => now(),
            'data_version' => $catalogVersion,
            'lag' => 0,
        ]);

        return $device;
    }
}
