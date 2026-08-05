<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Product;
use App\Models\ProductScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Раздел «Синхронизация устройств»: администратор видит не только когда телефон
 * выходил на связь, но и что продавец на нём смотрел сканером.
 */
class DeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    public function test_the_section_shows_the_last_scans_of_each_seller(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'name' => 'Огулджан Мурадова']);
        Device::factory()->create(['user_id' => $seller->id, 'point_id' => null]);

        $older = Product::factory()->create(['name' => 'ПЕРВЫЙ']);
        $newer = Product::factory()->create(['name' => 'ВТОРОЙ']);

        ProductScan::factory()->create([
            'user_id' => $seller->id,
            'product_id' => $older->id,
            'scanned_at' => now()->subHour(),
        ]);
        ProductScan::factory()->create([
            'user_id' => $seller->id,
            'product_id' => $newer->id,
            'scanned_at' => now(),
            'times' => 3,
        ]);

        $this->actingAs($this->admin())
            ->get('/devices')
            ->assertInertia(fn ($page) => $page
                ->component('Devices/Index')
                ->has('devices.0.scans', 2)
                ->where('devices.0.scans.0.name', 'ВТОРОЙ')
                ->where('devices.0.scans.0.times', 3)
                ->where('devices.0.scans.1.name', 'ПЕРВЫЙ'));
    }

    public function test_a_hidden_product_drops_out_of_the_panel_history_too(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        Device::factory()->create(['user_id' => $seller->id, 'point_id' => null]);

        ProductScan::factory()->create([
            'user_id' => $seller->id,
            'product_id' => Product::factory()->hidden()->create()->id,
        ]);

        $this->actingAs($this->admin())
            ->get('/devices')
            ->assertInertia(fn ($page) => $page->has('devices.0.scans', 0));
    }

    public function test_a_device_without_scans_carries_an_empty_history(): void
    {
        Device::factory()->create([
            'user_id' => User::factory()->create(['role' => 'seller'])->id,
            'point_id' => null,
        ]);

        $this->actingAs($this->admin())
            ->get('/devices')
            ->assertInertia(fn ($page) => $page->has('devices.0.scans', 0));
    }

    public function test_the_history_of_one_seller_does_not_leak_into_another_row(): void
    {
        $first = User::factory()->create(['role' => 'seller']);
        $second = User::factory()->create(['role' => 'seller']);

        Device::factory()->create(['user_id' => $first->id, 'point_id' => null]);
        Device::factory()->create(['user_id' => $second->id, 'point_id' => null]);

        ProductScan::factory()->create([
            'user_id' => $first->id,
            'product_id' => Product::factory()->create(['name' => 'ТОЛЬКО У ПЕРВОГО'])->id,
        ]);

        $this->actingAs($this->admin())
            ->get('/devices')
            ->assertInertia(fn ($page) => $page
                ->has('devices.0.scans', 1)
                ->where('devices.0.scans.0.name', 'ТОЛЬКО У ПЕРВОГО')
                ->has('devices.1.scans', 0));
    }
}
