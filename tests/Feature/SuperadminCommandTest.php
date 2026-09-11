<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SuperadminCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array{login?: ?string, password?: ?string, name?: string}  $overrides
     */
    private function configureCredentials(array $overrides = []): void
    {
        config()->set('aroma.superadmin', array_merge([
            'login' => 'chief',
            'password' => 'oченьДлинныйПароль1',
            'name' => 'Суперадмин',
        ], $overrides));
    }

    public function test_it_creates_the_superadmin_from_the_environment(): void
    {
        $this->configureCredentials();

        $this->artisan('aroma:superadmin')->assertSuccessful();

        $user = User::where('login', 'chief')->sole();

        $this->assertSame(UserRole::Superadmin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Auth::attempt(['login' => 'chief', 'password' => 'oченьДлинныйПароль1']));
    }

    public function test_it_rotates_the_password_of_an_existing_superadmin(): void
    {
        User::factory()->superadmin()->create(['login' => 'chief', 'password' => 'староеЗначение']);

        $this->configureCredentials();

        $this->artisan('aroma:superadmin')->assertSuccessful();

        $this->assertSame(1, User::where('login', 'chief')->count());
        $this->assertTrue(Auth::attempt(['login' => 'chief', 'password' => 'oченьДлинныйПароль1']));
    }

    public function test_it_refuses_to_run_without_credentials(): void
    {
        $this->configureCredentials(['login' => null, 'password' => null]);

        $this->artisan('aroma:superadmin')->assertFailed();

        $this->assertSame(0, User::where('role', UserRole::Superadmin)->count());
    }

    public function test_it_refuses_to_take_over_a_staff_login_without_force(): void
    {
        $seller = User::factory()->create(['login' => 'chief']);

        $this->configureCredentials();

        $this->artisan('aroma:superadmin')->assertFailed();

        $this->assertSame(UserRole::Seller, $seller->refresh()->role);
    }

    public function test_force_promotes_an_existing_staff_login(): void
    {
        $seller = User::factory()->create(['login' => 'chief']);

        $this->configureCredentials();

        $this->artisan('aroma:superadmin', ['--force' => true])->assertSuccessful();

        $this->assertSame(UserRole::Superadmin, $seller->refresh()->role);
    }

    public function test_the_seeded_console_is_reachable_with_the_issued_credentials(): void
    {
        $this->seedModules();
        $this->configureCredentials();
        $this->artisan('aroma:superadmin')->assertSuccessful();

        $this->post('/login', ['login' => 'chief', 'password' => 'oченьДлинныйПароль1', 'portal' => 'staff'])
            ->assertRedirect('/su');

        $this->get('/su')->assertOk();
    }
}
