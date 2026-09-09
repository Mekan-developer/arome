<?php

namespace App\Repositories\V2;

use App\Models\User;

/**
 * Сотрудник со стороны приложения. Панельных выборок здесь нет: телефону нужен
 * ровно один сценарий — найти учётную запись по логину при входе.
 */
class UserRepository
{
    public function findByLogin(string $login): ?User
    {
        return User::where('login', $login)->first();
    }

    /**
     * Один токен на устройство: повторный вход с того же телефона заменяет старый,
     * а не плодит их.
     */
    public function replaceDeviceToken(User $user, string $device): string
    {
        $user->tokens()->where('name', $device)->delete();

        return $user->createToken($device)->plainTextToken;
    }

    /**
     * Отметка последнего входа — то, что администратор видит в списке сотрудников.
     */
    public function markSignedIn(User $user, string $device): void
    {
        $user->forceFill(['last_login_at' => now(), 'device' => $device])->save();
    }
}
