<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductScan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductScan>
 */
class ProductScanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'device' => 'Redmi 12',
            'times' => 1,
            'scanned_at' => now(),
        ];
    }
}
