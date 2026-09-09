<?php

namespace App\Repositories\V2;

use App\Models\Device;

/**
 * Аппарат продавца. Раздел «Синхронизация» наполняется отсюда: до первой
 * синхронизации сервер о телефоне ничего не знает — вход выдаёт только токен.
 */
class DeviceRepository
{
    /**
     * Телефон попадает в раздел с первой же синхронизации. Точка остаётся пустой —
     * её назначает администратор, устройство её не выбирает.
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
     * Устройство догнало сервер: отставание обнуляется вместе с отметкой времени.
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
