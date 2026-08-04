<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'login' => fake()->unique()->userName(),
            'role' => 'seller',
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'last_login_at' => now(),
            'device' => 'Веб',
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin', 'device' => 'Веб']);
    }

    /**
     * Корневой администратор — учётка из ADMIN_LOGIN, единственная с разделом
     * «Пользователи».
     */
    public function root(): static
    {
        return $this->admin()->state(fn (array $attributes) => ['is_root' => true]);
    }

    public function superadmin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'superadmin', 'login' => 'root']);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
