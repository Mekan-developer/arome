<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Point;
use App\Models\User;
use App\Services\CatalogVersionService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'point_id' => Point::factory(),
            'model' => fake()->randomElement(['Redmi 12', 'Samsung A15', 'Tecno Spark']),
            'app_version' => '2.4.1',
            'synced_at' => now(),
            /* Телефон «в норме»: стоит ровно на той ревизии, что опубликована сейчас. */
            'data_version' => app(CatalogVersionService::class)->current(),
            'lag' => 0,
            'is_blocked' => false,
        ];
    }
}
