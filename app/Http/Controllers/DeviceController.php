<?php

namespace App\Http\Controllers;

use App\Enums\ModuleKey;
use App\Models\Device;
use App\Models\ProductScan;
use App\Repositories\DeviceRepository;
use App\Repositories\ProductScanRepository;
use App\Services\ModuleService;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    public function __construct(
        private readonly ModuleService $modules,
        private readonly DeviceRepository $devices,
        private readonly ProductScanRepository $scans,
    ) {}

    public function index(): Response
    {
        $withPoints = $this->modules->enabled(ModuleKey::Points->value);

        return Inertia::render('Devices/Index', [
            'catalogVersion' => config('aroma.catalog_version'),
            'devices' => function () use ($withPoints): Collection {
                $devices = $this->devices->all($withPoints);
                $history = $this->scans->forUsers(
                    $devices->pluck('user_id')->filter()->unique()->values()->all(),
                    (int) config('aroma.scan_history.limit'),
                );

                return $devices->map(fn (Device $device): array => [
                    'id' => $device->id,
                    'user' => $device->user?->name,
                    'point' => $withPoints ? $device->point?->name : null,
                    'model' => $device->model,
                    'appVersion' => $device->app_version,
                    'syncedAt' => $device->synced_at->format('d.m.Y H:i'),
                    'syncedAgo' => $this->ago($device),
                    'dataVersion' => $device->data_version,
                    'lag' => $device->lag,
                    'lagLabel' => $device->lag === 0 ? 'нет' : '−'.$device->lag.' ревизий',
                    'level' => $device->level(),
                    'note' => $this->note($device),
                    'scans' => $this->scanRows($history->get($device->user_id)),
                ]);
            },
        ]);
    }

    /**
     * Последние товары, которые сотрудник смотрел сканером. Пусто — значит продавец
     * ещё ни разу не подносил телефон к ценнику.
     *
     * @param  iterable<int, ProductScan>|null  $scans
     * @return list<array{name: string, sku: string, at: string, time: string, times: int}>
     */
    private function scanRows(?iterable $scans): array
    {
        return collect($scans ?? [])
            ->map(fn (ProductScan $scan): array => [
                'name' => (string) $scan->product?->name,
                'sku' => (string) $scan->product?->sku,
                'at' => $scan->scanned_at->format('d.m.Y H:i'),
                'time' => $scan->scanned_at->format('H:i'),
                'times' => (int) $scan->times,
            ])
            ->values()
            ->all();
    }

    /**
     * «14 минут назад», «18 часов назад», «4 дня назад» — relative to the demo clock.
     */
    private function ago(Device $device): string
    {
        $minutes = (int) round($device->synced_at->diffInMinutes(now()));

        if ($minutes < 60) {
            return $minutes.' '.$this->plural($minutes, 'минуту', 'минуты', 'минут').' назад';
        }

        $hours = (int) round($minutes / 60);

        if ($hours < 24) {
            return $hours.' '.$this->plural($hours, 'час', 'часа', 'часов').' назад';
        }

        $days = (int) round($hours / 24);

        return $days.' '.$this->plural($days, 'день', 'дня', 'дней').' назад';
    }

    private function note(Device $device): string
    {
        return match ($device->level()) {
            'blocked' => 'доступ заблокирован, сессия завершена',
            'ok' => 'данные актуальны',
            default => 'нужна синхронизация',
        };
    }

    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        return match (true) {
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };
    }
}
