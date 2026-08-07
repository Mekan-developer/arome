<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Права живут в Policy, а не в проверках внутри контроллеров, — поэтому проверяются
 * и напрямую через Gate, и через маршруты.
 */
class PolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    public function test_only_the_catalog_managers_may_change_products(): void
    {
        $product = Product::factory()->create();

        $this->assertTrue(Gate::forUser($this->admin())->allows('update', $product));
        $this->assertTrue(Gate::forUser(User::factory()->superadmin()->create())->allows('update', $product));
        $this->assertFalse(Gate::forUser(User::factory()->create(['role' => 'seller']))->allows('update', $product));
    }

    public function test_only_the_catalog_managers_may_delete_products(): void
    {
        $product = Product::factory()->create();

        $this->assertTrue(Gate::forUser($this->admin())->allows('delete', $product));
        $this->assertTrue(Gate::forUser(User::factory()->superadmin()->create())->allows('delete', $product));
        $this->assertFalse(Gate::forUser(User::factory()->create(['role' => 'seller']))->allows('delete', $product));
    }

    public function test_a_seller_cannot_bulk_edit_prices(): void
    {
        $products = Product::factory()->count(2)->create();

        $this->actingAs(User::factory()->create(['role' => 'seller']))
            ->post('/products/bulk', [
                'ids' => $products->pluck('id')->all(),
                'mode' => 'discount',
                'value' => 50,
            ])
            ->assertForbidden();
    }

    public function test_a_seller_cannot_hide_products(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'seller']))
            ->post('/products/hide', ['ids' => [$product->id]])
            ->assertForbidden();

        $this->assertSame('active', $product->refresh()->status->value);
    }

    public function test_a_seller_cannot_block_a_colleague(): void
    {
        $target = User::factory()->create(['role' => 'seller']);

        $this->actingAs(User::factory()->create(['role' => 'seller']))
            ->patch("/users/{$target->id}/access")
            ->assertForbidden();

        $this->assertTrue($target->refresh()->is_active);
    }

    /**
     * Учётную запись суперадмина не трогают из панели администратора: роль скрыта
     * из списка сотрудников, и доступ к ней оттуда не выдаётся.
     */
    public function test_the_superadmin_account_cannot_be_managed_from_the_panel(): void
    {
        $root = User::factory()->superadmin()->create();

        $this->assertFalse(Gate::forUser($this->admin())->allows('manageAccess', $root));
        $this->assertFalse(Gate::forUser($this->admin())->allows('update', $root));

        $this->actingAs($this->admin())
            ->patch("/users/{$root->id}/access")
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->put("/users/{$root->id}", ['name' => $root->name, 'login' => $root->login, 'role' => 'admin'])
            ->assertForbidden();
    }

    /**
     * Раздел «Пользователи» принадлежит корневой учётке из ADMIN_LOGIN. Созданный ею
     * администратор ведёт каталог, но раздачу доступов не наследует.
     */
    public function test_only_the_root_administrator_reaches_the_staff_section(): void
    {
        $plain = User::factory()->admin()->create();

        $this->assertTrue(Gate::forUser($this->admin())->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser(User::factory()->superadmin()->create())->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($plain)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser(User::factory()->create(['role' => 'seller']))->allows('viewAny', User::class));

        $this->actingAs($plain)->get('/users')->assertForbidden();
    }

    public function test_a_plain_administrator_cannot_issue_or_change_accounts(): void
    {
        $plain = User::factory()->admin()->create();
        $target = User::factory()->create(['role' => 'seller', 'name' => 'Гөзел Сапарова']);

        $this->actingAs($plain)
            ->post('/users', ['name' => 'Новый Сотрудник', 'login' => 'novyi', 'role' => 'admin', 'password' => 'parol123'])
            ->assertForbidden();

        $this->actingAs($plain)
            ->put("/users/{$target->id}", ['name' => 'Другое имя', 'login' => $target->login, 'role' => 'seller'])
            ->assertForbidden();

        $this->actingAs($plain)->patch("/users/{$target->id}/access")->assertForbidden();
        $this->actingAs($plain)->put("/users/{$target->id}/password", ['password' => 'parol123'])->assertForbidden();

        $this->assertDatabaseMissing('users', ['login' => 'novyi']);
        $this->assertSame('Гөзел Сапарова', $target->refresh()->name);
        $this->assertTrue($target->is_active);
    }

    /**
     * Владелец раздела не запирает себя снаружи: свой доступ не отзывается, своя роль
     * не понижается — иначе «Пользователи» пропадут у всех сразу.
     */
    public function test_the_root_administrator_cannot_lock_himself_out(): void
    {
        $root = $this->admin();

        $this->assertFalse(Gate::forUser($root)->allows('manageAccess', $root));

        $this->actingAs($root)->patch("/users/{$root->id}/access")->assertForbidden();

        $this->actingAs($root)
            ->put("/users/{$root->id}", ['name' => $root->name, 'login' => $root->login, 'role' => 'seller'])
            ->assertSessionHasErrors('role');

        $root->refresh();
        $this->assertTrue($root->is_active);
        $this->assertSame('admin', $root->role->value);
    }

    public function test_a_seller_cannot_edit_a_colleague(): void
    {
        $target = User::factory()->create(['role' => 'seller', 'name' => 'Гөзел Сапарова']);

        $this->actingAs(User::factory()->create(['role' => 'seller']))
            ->put("/users/{$target->id}", ['name' => 'Другое имя', 'login' => $target->login, 'role' => 'seller'])
            ->assertForbidden();

        $this->assertSame('Гөзел Сапарова', $target->refresh()->name);
    }

    public function test_only_the_superadmin_may_toggle_a_module(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->superadmin()->create())->allows('toggle', Module::class));
        $this->assertFalse(Gate::forUser($this->admin())->allows('toggle', Module::class));
    }

    public function test_a_seller_cannot_edit_the_rights_matrix(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'seller']))
            ->put('/rights', ['fields' => ['retail' => false]])
            ->assertForbidden();
    }

    public function test_the_superadmin_never_appears_in_the_staff_list(): void
    {
        User::factory()->superadmin()->create();
        User::factory()->create(['role' => 'seller', 'name' => 'Гөзел Сапарова']);

        $this->actingAs($this->admin())
            ->get('/users')
            ->assertInertia(fn ($page) => $page->where(
                'staff',
                fn ($staff) => collect($staff)->every(fn (array $row): bool => $row['role'] !== 'superadmin'),
            ));
    }
}
