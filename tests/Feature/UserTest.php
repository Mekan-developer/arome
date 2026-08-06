<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Point;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    public function test_the_staff_list_labels_each_role(): void
    {
        User::factory()->create(['role' => 'admin', 'name' => 'Мерет Атаев']);
        User::factory()->create(['role' => 'seller', 'name' => 'Гөзел Сапарова']);

        $this->actingAs($this->admin())
            ->get('/users')
            ->assertInertia(fn ($page) => $page->where(
                'staff',
                fn ($staff) => collect($staff)
                    ->every(fn (array $row): bool => $row['roleTitle'] === ($row['role'] === 'admin' ? 'Администратор' : 'Продавец')),
            ));
    }

    /**
     * Вкладка «Пользователи» уходит из шапки у всех, кроме владельца раздела, —
     * маршрут закрыт, но и ссылки на него взгляд не находит.
     */
    public function test_the_tab_strip_shows_the_staff_section_only_to_the_root_administrator(): void
    {
        $this->actingAs($this->admin())
            ->get('/products')
            ->assertInertia(fn ($page) => $page->where(
                'sections',
                fn ($sections) => collect($sections)->firstWhere('key', 'users')['visible'] === true,
            ));

        $this->actingAs(User::factory()->admin()->create())
            ->get('/products')
            ->assertInertia(fn ($page) => $page->where(
                'sections',
                fn ($sections) => collect($sections)->firstWhere('key', 'users')['visible'] === false,
            ));
    }

    public function test_the_root_administrator_issues_administrators_who_do_not_inherit_the_section(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'Мерет Атаев',
                'login' => 'meret',
                'role' => 'admin',
                'password' => 'parol123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $created = User::where('login', 'meret')->firstOrFail();
        $this->assertSame('admin', $created->role->value);
        $this->assertFalse($created->is_root);
        $this->assertFalse($created->managesStaff());
    }

    public function test_an_admin_can_edit_a_colleagues_name_login_and_role(): void
    {
        $target = User::factory()->create(['name' => 'Гөзел Сапарова', 'login' => 'gozel', 'role' => 'seller']);

        $this->actingAs($this->admin())
            ->put("/users/{$target->id}", [
                'name' => 'Гөзел Аннаева',
                'login' => 'gannaeva',
                'role' => 'admin',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $target->refresh();
        $this->assertSame('Гөзел Аннаева', $target->name);
        $this->assertSame('gannaeva', $target->login);
        $this->assertSame('admin', $target->role->value);

        $this->assertDatabaseHas('audit_logs', ['action' => 'Изменены данные сотрудника', 'kind' => 'user']);
    }

    /**
     * Свою роль не понижают: администратор, ушедший в продавцы, закрыл бы раздел
     * «Пользователи» для всех сразу.
     */
    public function test_an_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", [
                'name' => 'Новое Имя',
                'login' => $admin->login,
                'role' => 'seller',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->refresh()->role->value);
        $this->assertNotSame('Новое Имя', $admin->name);
    }

    public function test_the_login_must_stay_unique_but_may_be_kept_unchanged(): void
    {
        $target = User::factory()->create(['login' => 'gozel']);
        User::factory()->create(['login' => 'annaeva']);

        $this->actingAs($this->admin())
            ->put("/users/{$target->id}", ['name' => $target->name, 'login' => 'annaeva', 'role' => 'seller'])
            ->assertSessionHasErrors('login');

        $this->actingAs($this->admin())
            ->put("/users/{$target->id}", ['name' => $target->name, 'login' => 'gozel', 'role' => 'seller'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_editing_a_staff_member_syncs_their_points_when_the_module_is_enabled(): void
    {
        $this->seedModules(['points' => true]);
        $target = User::factory()->create(['login' => 'gozel']);
        [$keep, $drop, $add] = Point::factory()->count(3)->create();
        $target->points()->sync([$keep->id, $drop->id]);

        $this->actingAs($this->admin())
            ->put("/users/{$target->id}", [
                'name' => $target->name,
                'login' => 'gozel',
                'role' => 'seller',
                'points' => [$keep->id, $add->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame([$keep->id, $add->id], $target->points()->pluck('points.id')->sort()->values()->all());
    }

    /**
     * Блокировка бьёт по уже открытой панели: следующий же запрос заблокированного
     * администратора уходит на экран входа с его собственным сообщением.
     */
    public function test_blocking_a_staff_member_drops_their_open_panel_session(): void
    {
        $target = User::factory()->admin()->create();

        $this->actingAs($this->admin())
            ->patch("/users/{$target->id}/access")
            ->assertRedirect();

        $this->actingAs($target->refresh())
            ->get('/products')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['login' => 'blocked']);

        $this->assertGuest();
    }

    public function test_returning_access_lets_the_staff_member_back_into_the_panel(): void
    {
        $target = User::factory()->admin()->create(['is_active' => false]);

        $this->actingAs($this->admin())
            ->patch("/users/{$target->id}/access")
            ->assertRedirect();

        $this->actingAs($target->refresh())
            ->get('/products')
            ->assertOk();
    }

    /**
     * «Завершить активные сессии»: старый токен продавца перестаёт работать сразу, а его
     * устройство приходит за каталогом заново.
     */
    public function test_changing_a_password_with_end_sessions_revokes_the_sellers_token(): void
    {
        /* Иначе сброс устройства на нулевую ревизию нечем отличить от пустого каталога. */
        $this->publishCatalogRevision(4193);

        $target = User::factory()->create(['login' => 'gozel']);
        $token = $target->createToken('seller')->plainTextToken;
        $device = Device::factory()->for($target)->create();

        $this->actingAs($this->admin())
            ->put("/users/{$target->id}/password", ['password' => 'parol123', 'end_sessions' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue(Hash::check('parol123', $target->refresh()->password));
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(0, $device->refresh()->data_version);

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/products')
            ->assertUnauthorized();

        $this->assertDatabaseHas('audit_logs', ['action' => 'Смена пароля', 'kind' => 'user']);
    }

    public function test_changing_a_password_without_end_sessions_leaves_the_seller_logged_in(): void
    {
        $this->publishCatalogRevision(4193);

        $target = User::factory()->create(['login' => 'gozel']);
        $device = Device::factory()->for($target)->create();
        $target->createToken('seller');

        $this->actingAs($this->admin())
            ->put("/users/{$target->id}/password", ['password' => 'parol123', 'end_sessions' => false])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue(Hash::check('parol123', $target->refresh()->password));
        $this->assertSame(1, $target->tokens()->count());
        $this->assertSame(4193, $device->refresh()->data_version);
    }

    /**
     * Without the points module the edit modal has no points field at all — a request
     * carrying no points must not wipe assignments made while the module was on.
     */
    public function test_editing_a_staff_member_leaves_points_untouched_when_the_module_is_disabled(): void
    {
        $this->seedModules(['points' => true]);
        $target = User::factory()->create(['login' => 'gozel']);
        $point = Point::factory()->create();
        $target->points()->sync([$point->id]);

        $this->seedModules(['points' => false]);

        $this->actingAs($this->admin())
            ->put("/users/{$target->id}", ['name' => $target->name, 'login' => 'gozel', 'role' => 'seller'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame([$point->id], $target->points()->pluck('points.id')->all());
    }
}
