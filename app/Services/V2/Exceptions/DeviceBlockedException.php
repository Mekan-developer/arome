<?php

namespace App\Services\V2\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Аппарат заблокирован администратором — сессия на нём завершается.
 */
class DeviceBlockedException extends ApiException
{
    public function __construct()
    {
        parent::__construct('Доступ заблокирован, сессия завершена');
    }

    public function errorCode(): string
    {
        return 'device_blocked';
    }

    public function status(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
