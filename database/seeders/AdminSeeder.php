<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Единственная учётка, с которой начинается пустая база: администратор панели.
 * Логин и пароль берутся из .env, повторный запуск обновляет пароль, а не плодит юзеров.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $login = config('aroma.admin.login');
        $password = config('aroma.admin.password');

        if (blank($login) || blank($password)) {
            $this->command?->error('Не заданы ADMIN_LOGIN и ADMIN_PASSWORD в .env — администратор не создан.');

            return;
        }

        $admin = User::firstOrNew(['login' => $login]);
        $existed = $admin->exists;

        // `is_root` намеренно вне Fillable: флаг ставится здесь и больше нигде,
        // так что из панели его себе не выписать.
        $admin->forceFill([
            'name' => config('aroma.admin.name'),
            'role' => UserRole::Admin,
            'password' => $password,
            'is_active' => true,
            'is_root' => true,
            'device' => 'Веб',
        ])->save();

        $this->command?->info($existed
            ? "Пароль администратора «{$login}» обновлён."
            : "Администратор «{$login}» создан.");
    }
}
