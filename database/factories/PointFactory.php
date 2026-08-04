<?php

namespace Database\Factories;

use App\Models\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Point>
 */
class PointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => mb_strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->company(),
            'address' => fake()->address(),
            'is_warehouse' => false,
            'is_active' => true,
        ];
    }

    public function warehouse(): static
    {
        return $this->state(fn (array $attributes) => ['is_warehouse' => true]);
    }
}
