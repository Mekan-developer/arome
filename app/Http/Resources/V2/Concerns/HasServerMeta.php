<?php

namespace App\Http\Resources\V2\Concerns;

/**
 * `meta.server_time` есть в каждом ответе v2: телефон в зале живёт со своими часами,
 * и «последние товары» без серверного времени читаются неправильно.
 *
 * Собирается одним местом, чтобы ответы версии не разъезжались по составу мета-полей.
 */
trait HasServerMeta
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function serverMeta(array $extra = []): array
    {
        return [...$extra, 'server_time' => now()->toIso8601ZuluString()];
    }
}
