<?php

namespace App\Services\V2\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Неверный логин или пароль — одним ответом на оба случая: иначе перебором логинов
 * узнают, какие учётные записи существуют.
 */
class InvalidCredentialsException extends ApiException
{
    public function __construct()
    {
        parent::__construct('Неверный логин или пароль');
    }

    public function errorCode(): string
    {
        return 'invalid_credentials';
    }

    public function status(): int
    {
        return Response::HTTP_UNAUTHORIZED;
    }
}
