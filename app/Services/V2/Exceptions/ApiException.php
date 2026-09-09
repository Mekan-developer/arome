<?php

namespace App\Services\V2\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Отказ, предусмотренный договором с приложением: не найден товар, не тот пароль,
 * заблокированный аппарат. Сервис бросает такое исключение и на этом заканчивает —
 * форму ответа знает базовый класс, а не контроллер.
 *
 * Так `{"error": {"code", "message"}}` описан в одном месте: контроллер v2 не
 * собирает JSON ошибок руками и не может разойтись с соседним контроллером.
 */
abstract class ApiException extends RuntimeException
{
    /**
     * Машинный код: приложение разбирает его, а не текст сообщения.
     */
    abstract public function errorCode(): string;

    abstract public function status(): int;

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode(),
                'message' => $this->getMessage(),
            ],
        ], $this->status());
    }

    /**
     * В лог не пишем: это штатный ответ продавцу, а не сбой сервера.
     */
    public function report(): bool
    {
        return true;
    }
}
