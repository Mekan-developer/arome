<?php

namespace App\Services\V2\Data;

/**
 * То, что телефон сообщает о себе при синхронизации: как он называется, какая на нём
 * версия приложения и какую ревизию каталога он уже применил.
 */
final class DeviceHeartbeat
{
    public function __construct(
        public readonly string $deviceName,
        public readonly ?string $appVersion,
        public readonly int $sinceVersion,
    ) {}
}
