<?php

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\Point;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    public function test_it_reports_how_far_behind_the_device_is(): void
    {
        $user = User::factory()->create(['role' => 'seller']);
        Device::factory()->create(['user_id' => $user->id, 'point_id' => Point::factory(), 'data_version' => 4102, 'lag' => 91]);

        $this->asDevice($user)
            ->postJson('/api/v1/sync', ['data_version' => 4102])
            ->assertOk()
            ->assertJsonPath('data.catalog_version', config('aroma.catalog_version'))
            ->assertJsonPath('data.previous_version', 4102)
            ->assertJsonPath('data.lag', 91)
            ->assertJsonPath('data.up_to_date', false);
    }

    public function test_a_device_on_the_current_revision_is_up_to_date(): void
    {
        $user = User::factory()->create(['role' => 'seller']);
        Device::factory()->create(['user_id' => $user->id, 'point_id' => Point::factory()]);

        $this->asDevice($user)
            ->postJson('/api/v1/sync', ['data_version' => config('aroma.catalog_version')])
            ->assertOk()
            ->assertJsonPath('data.lag', 0)
            ->assertJsonPath('data.up_to_date', true);
    }

    public function test_syncing_brings_the_device_up_to_the_server_revision(): void
    {
        $user = User::factory()->create(['role' => 'seller']);
        $device = Device::factory()->create([
            'user_id' => $user->id,
            'point_id' => Point::factory(),
            'data_version' => 3988,
            'lag' => 205,
        ]);

        $this->asDevice($user)->postJson('/api/v1/sync', ['data_version' => 3988])->assertOk();

        $device->refresh();

        $this->assertSame((int) config('aroma.catalog_version'), $device->data_version);
        $this->assertSame(0, $device->lag);
        $this->assertSame('ok', $device->level());
    }

    /**
     * Блокировка отзывает токен немедленно — устройство выкидывает на экран входа.
     */
    public function test_a_blocked_device_is_refused(): void
    {
        $user = User::factory()->create(['role' => 'seller']);
        Device::factory()->create(['user_id' => $user->id, 'point_id' => Point::factory(), 'is_blocked' => true]);

        $this->asDevice($user)
            ->postJson('/api/v1/sync', ['data_version' => 4000])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'device_blocked');
    }

    public function test_sync_is_not_public(): void
    {
        $this->postJson('/api/v1/sync', ['data_version' => 4000])->assertUnauthorized();
    }
}
