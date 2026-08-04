<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\CatalogGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = fake()->unique()->numberBetween(0, 99999);

        return [
            'main_code' => 'AA'.(1001 + $index),
            'sku' => (string) (510000 + $index * 13),
            // A 12-digit body keyed off the index, so no two rows share a barcode.
            'barcode' => CatalogGenerator::ean13('8011'.str_pad((string) $index, 8, '0', STR_PAD_LEFT)),
            'name' => mb_strtoupper(fake()->words(3, true)).' EDT 50ML',
            'kind' => fake()->randomElement(['PARFUM', 'EDP', 'EDT', 'CARE']),
            'price' => fake()->randomFloat(2, 180, 2580),
            'discount' => 0,
            'status' => ProductStatus::Active->value,
        ];
    }

    public function discounted(float $discount = 0.5): static
    {
        return $this->state(fn (array $attributes) => ['discount' => $discount]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ProductStatus::Hidden->value]);
    }
}
