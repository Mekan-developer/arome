<?php

namespace App\Services\V2\Data;

/**
 * Вход с телефона: логин, пароль и имя устройства. Имя — не косметика, по нему
 * выдаётся и отзывается токен конкретного аппарата.
 */
final class DeviceCredentials
{
    public function __construct(
        public readonly string $login,
        public readonly string $password,
        public readonly string $device,
    ) {}
}
