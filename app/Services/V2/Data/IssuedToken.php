<?php

namespace App\Services\V2\Data;

use App\Models\User;

/**
 * Выданный токен. Открытая строка живёт только внутри этого ответа: в базе лежит
 * её хеш, и повторить выдачу того же значения нельзя.
 */
final class IssuedToken
{
    public function __construct(
        public readonly string $plainTextToken,
        public readonly User $user,
    ) {}
}
