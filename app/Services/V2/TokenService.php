<?php

namespace App\Services\V2;

use App\Models\User;
use App\Repositories\V2\UserRepository;
use App\Services\AuditService;
use App\Services\V2\Data\DeviceCredentials;
use App\Services\V2\Data\IssuedToken;
use App\Services\V2\Exceptions\AccountBlockedException;
use App\Services\V2\Exceptions\InvalidCredentialsException;
use Illuminate\Support\Facades\Hash;

/**
 * Вход и выход с устройства. Токен именной: «выйти на потерянном телефоне» — это
 * отзыв конкретного аппарата, а не всех сессий сотрудника.
 */
class TokenService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditService $audit,
    ) {}

    /**
     * Проверка пароля идёт до проверки блокировки, а не наоборот: иначе по разнице
     * ответов на верный и неверный пароль узнают, что учётная запись существует.
     *
     * @throws InvalidCredentialsException|AccountBlockedException
     */
    public function issue(DeviceCredentials $credentials): IssuedToken
    {
        $user = $this->users->findByLogin($credentials->login);

        if ($user === null || ! Hash::check($credentials->password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        if (! $user->is_active) {
            throw new AccountBlockedException;
        }

        $token = $this->users->replaceDeviceToken($user, $credentials->device);
        $this->users->markSignedIn($user, $credentials->device);

        $this->audit->record($user->name, 'Вход с устройства', $credentials->device, null, null, 'auth');

        return new IssuedToken($token, $user);
    }

    /**
     * Выход: отзывается только токен этого устройства.
     */
    public function revoke(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
