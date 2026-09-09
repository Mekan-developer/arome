<?php

namespace Tests\Feature\Api\V2;

use App\Models\AuditLog;
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
        $this->publishCatalogRevision(4193);

        $user = User::factory()->create(['role' => 'seller']);
        Device::factory()->create(['user_id' => $user->id, 'point_id' => Point::factory(), 'data_version' => 4102, 'lag' => 91]);

        $this->asDevice($user)
            ->postJson('/api/v2/sync', ['data_version' => 4102])
            ->assertOk()
            ->assertJsonPath('data.catalog_version', 4193)
            ->assertJsonPath('data.previous_version', 4102)
            ->assertJsonPath('data.lag', 91)
            ->assertJsonPath('data.up_to_date', false)
            ->assertJsonStructure(['data', 'meta' => ['server_time']]);
    }

    public function test_a_device_on_the_current_revision_is_up_to_date(): void
    {
        $this->publishCatalogRevision(4193);

        $user = User::factory()->create(['role' => 'seller']);
        Device::factory()->create(['user_id' => $user->id, 'point_id' => Point::factory()]);

        $this->asDevice($user)
            ->postJson('/api/v2/sync', ['data_version' => 4193])
            ->assertOk()
            ->assertJsonPath('data.lag', 0)
            ->assertJsonPath('data.up_to_date', true);
    }

    public function test_syncing_brings_the_device_up_to_the_server_revision(): void
    {
        $this->publishCatalogRevision(4193);

        $user = User::factory()->create(['role' => 'seller']);
        $device = Device::factory()->create([
            'user_id' => $user->id,
            'point_id' => Point::factory(),
            'data_version' => 3988,
            'lag' => 205,
        ]);

        $this->asDevice($user)->postJson('/api/v2/sync', ['data_version' => 3988])->assertOk();

        $device->refresh();

        $this->assertSame(4193, $device->data_version);
        $this->assertSame(0, $device->lag);
        $this->assertSame('ok', $device->level());
    }

    /**
     * Полученная дельта попадает в журнал, догнавший телефон — нет: иначе журнал
     * состоит из строк «ничего не изменилось».
     */
    public function test_only_a_real_delta_is_written_into_the_journal(): void
    {
        $this->publishCatalogRevision(4193);

        $user = User::factory()->create(['role' => 'seller', 'name' => 'Огулджан Мурадова']);

        $this->asDevice($user, 'Redmi 12')->postJson('/api/v2/sync', ['data_version' => 4102])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor' => 'Огулджан Мурадова',
            'action' => 'Синхронизация устройства',
            'value_from' => '4102',
            'value_to' => '4193',
        ]);

        $this->assertSame(1, AuditLog::where('action', 'Синхронизация устройства')->count());

        $this->asDevice($user, 'Redmi 12')->postJson('/api/v2/sync', ['data_version' => 4193])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'Синхронизация устройства')->count());
    }

    /**
     * До первой синхронизации сервер знает о телефоне только то, что он получил токен.
     * Раздел «Синхронизация устройств» наполняется именно этим запросом.
     */
    public function test_the_first_sync_puts_a_new_phone_into_the_section(): void
    {
        $this->publishCatalogRevision(4193);

        $user = User::factory()->create(['role' => 'seller']);

        $this->asDevice($user, 'Samsung A15')
            ->postJson('/api/v2/sync', ['data_version' => 0, 'app_version' => '2.4.1'])
            ->assertOk()
            // Телефон приходит с нуля: дельту он получает целиком, а строка уже актуальна.
            ->assertJsonPath('data.lag', 4193)
            ->assertJsonPath('data.up_to_date', false);

        $device = Device::where('user_id', $user->id)->sole();

        $this->assertSame('Samsung A15', $device->model);
        $this->assertSame('2.4.1', $device->app_version);
        $this->assertSame(4193, $device->data_version);
        $this->assertSame(0, $device->lag);
        $this->assertNull($device->point_id);
    }

    public function test_the_registered_phone_is_visible_to_the_administrator(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'name' => 'Огулджан Мурадова']);

        $this->asDevice($seller, 'Tecno Spark')->postJson('/api/v2/sync', ['data_version' => 0])->assertOk();

        $this->actingAs($this->admin())
            ->get('/devices')
            ->assertInertia(fn ($page) => $page
                ->component('Devices/Index')
                ->has('devices', 1)
                ->where('devices.0.user', 'Огулджан Мурадова')
                ->where('devices.0.model', 'Tecno Spark')
                ->where('devices.0.note', 'данные актуальны'));
    }

    public function test_a_repeat_sync_reuses_the_row_and_refreshes_the_app_version(): void
    {
        $user = User::factory()->create(['role' => 'seller']);

        $this->asDevice($user, 'Redmi 12')->postJson('/api/v2/sync', ['app_version' => '2.4.1'])->assertOk();
        $this->asDevice($user, 'Redmi 12')->postJson('/api/v2/sync', ['app_version' => '2.5.0'])->assertOk();

        $this->assertSame(1, Device::where('user_id', $user->id)->count());
        $this->assertSame('2.5.0', Device::where('user_id', $user->id)->sole()->app_version);
    }

    /**
     * Телефон может не сообщать версию приложения — строка в разделе всё равно нужна.
     */
    public function test_a_phone_without_an_app_version_still_registers(): void
    {
        $user = User::factory()->create(['role' => 'seller']);

        $this->asDevice($user)->postJson('/api/v2/sync', ['data_version' => 4102])->assertOk();

        $this->assertSame('—', Device::where('user_id', $user->id)->sole()->app_version);
    }

    public function test_a_broken_revision_number_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'seller']);

        $this->asDevice($user)
            ->postJson('/api/v2/sync', ['data_version' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('data_version');
    }

    /**
     * Блокировка отзывает доступ немедленно — устройство выкидывает на экран входа.
     */
    public function test_a_blocked_device_is_refused(): void
    {
        $user = User::factory()->create(['role' => 'seller']);
        Device::factory()->create(['user_id' => $user->id, 'point_id' => Point::factory(), 'is_blocked' => true]);

        $this->asDevice($user)
            ->postJson('/api/v2/sync', ['data_version' => 4000])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'device_blocked');
    }

    /**
     * Отказ блокированному аппарату — это отказ, а не тихая синхронизация: отметка
     * устройства остаётся прежней.
     */
    public function test_a_blocked_device_is_not_marked_as_synced(): void
    {
        $this->publishCatalogRevision(4193);

        $user = User::factory()->create(['role' => 'seller']);
        $device = Device::factory()->create([
            'user_id' => $user->id,
            'point_id' => Point::factory(),
            'is_blocked' => true,
            'data_version' => 4000,
        ]);

        $this->asDevice($user)->postJson('/api/v2/sync', ['data_version' => 4000])->assertForbidden();

        $this->assertSame(4000, $device->fresh()->data_version);
    }

    public function test_sync_is_not_public(): void
    {
        $this->postJson('/api/v2/sync', ['data_version' => 4000])->assertUnauthorized();
    }
}
