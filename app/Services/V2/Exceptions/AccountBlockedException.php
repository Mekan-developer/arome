<?php

namespace App\Services\V2\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Учётная запись заблокирована в панели: пароль верный, но токен не выдаётся.
 */
class AccountBlockedException extends ApiException
{
    public function __construct()
    {
        parent::__construct('Учётная запись заблокирована');
    }

    public function errorCode(): string
    {
        return 'account_blocked';
    }

    public function status(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
