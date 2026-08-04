<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Создаёт (или обновляет) служебную учётку суперадмина по значениям из .env.
 * Пароль нигде не выводится — только логин, под которым заходить.
 */
#[Signature('aroma:superadmin {--force : Повысить существующую учётку до суперадмина}')]
#[Description('Выдаёт доступ к служебной консоли /su по SUPERADMIN_LOGIN и SUPERADMIN_PASSWORD из .env')]
class SuperadminCommand extends Command
{
    public function handle(): int
    {
        $login = config('aroma.superadmin.login');
        $password = config('aroma.superadmin.password');

        if (blank($login) || blank($password)) {
            $this->components->error('Не заданы SUPERADMIN_LOGIN и SUPERADMIN_PASSWORD в .env.');

            return self::FAILURE;
        }

        if (mb_strlen((string) $password) < 8) {
            $this->components->warn('SUPERADMIN_PASSWORD короче 8 символов — смените его перед боевым запуском.');
        }

        $existing = User::where('login', $login)->first();

        if ($existing && ! $existing->isSuperadmin() && ! $this->option('force')) {
            $this->components->error("Логин «{$login}» уже занят сотрудником ({$existing->role->label()}). Повысить его до суперадмина: --force.");

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['login' => $login], [
            'name' => config('aroma.superadmin.name'),
            'role' => UserRole::Superadmin,
            'password' => $password,
            'is_active' => true,
            'device' => 'Веб',
        ]);

        $this->components->info($user->wasRecentlyCreated
            ? "Суперадмин «{$login}» создан."
            : "Пароль и доступ суперадмина «{$login}» обновлены.");

        $this->components->twoColumnDetail('Вход', url('/login'));
        $this->components->twoColumnDetail('Консоль', url('/su'));

        return self::SUCCESS;
    }
}
