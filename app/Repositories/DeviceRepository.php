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
     * Телефон попадает в раздел с первой же синхронизации: до неё сервер о нём ничего
     * не знает, потому что вход выдаёт только токен. Точка остаётся пустой — её
     * назначает администратор, устройство её не выбирает.
     */
    public function registerForUser(int $userId, string $model, ?string $appVersion): Device
    {
        $device = Device::firstOrCreate(
            ['user_id' => $userId],
            [
                'model' => $model,
                'app_version' => $appVersion ?? '—',
                'synced_at' => now(),
                'data_version' => 0,
                'lag' => 0,
            ],
        );

        // Обновление приложения на телефоне должно быть видно в разделе сразу.
        if ($appVersion !== null && $device->app_version !== $appVersion) {
            $device->update(['app_version' => $appVersion]);
        }

        return $device;
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
