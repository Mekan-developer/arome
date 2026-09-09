<?php

namespace App\Services\V2\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Штрихкод не найден. Скрытый товар отвечает так же, как чужой: для приложения
 * продавца разницы между «нет карточки» и «карточка спрятана» не существует.
 */
class ProductNotFoundException extends ApiException
{
    public function __construct()
    {
        parent::__construct('Товар с таким штрихкодом не найден');
    }

    public function errorCode(): string
    {
        return 'product_not_found';
    }

    public function status(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
