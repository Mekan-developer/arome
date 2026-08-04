<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пустая база превращается в рабочую панель одним сидером: он выдаёт единственную
 * учётку, с которой заходят в первый раз, и помечает её корневой.
 */
class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_root_administrator_from_the_configured_credentials(): void
    {
        config(['aroma.admin' => ['login' => 'admin', 'password' => 'admin12345', 'name' => 'Администратор']]);

        $this->seed(AdminSeeder::class);

        $admin = User::where('login', 'admin')->firstOrFail();

        $this->assertSame('Администратор', $admin->name);
        $this->assertSame('admin', $admin->role->value);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->isRootAdmin());
        $this->assertTrue($this->app['hash']->check('admin12345', $admin->password));
    }

    /**
     * Контейнер сидит базу на каждом старте: повторный проход освежает пароль из .env,
     * а не заводит второго администратора.
     */
    public function test_running_it_again_refreshes_the_password_without_duplicating_the_account(): void
    {
        config(['aroma.admin' => ['login' => 'admin', 'password' => 'admin12345', 'name' => 'Администратор']]);
        $this->seed(AdminSeeder::class);

        config(['aroma.admin' => ['login' => 'admin', 'password' => 'drugoi123', 'name' => 'Администратор']]);
        $this->seed(AdminSeeder::class);

        $this->assertSame(1, User::where('login', 'admin')->count());
        $this->assertTrue($this->app['hash']->check('drugoi123', User::where('login', 'admin')->value('password')));
    }

    public function test_it_stops_short_when_the_credentials_are_missing(): void
    {
        config(['aroma.admin' => ['login' => null, 'password' => null, 'name' => 'Администратор']]);

        $this->seed(AdminSeeder::class);

        $this->assertSame(0, User::count());
    }
}
