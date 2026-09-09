<?php

namespace App\Services\V2\Data;

/**
 * Итог синхронизации: актуальная ревизия каталога и то, насколько телефон от неё
 * отстал. По `lag` раздел «Синхронизация» красит строки, по `up_to_date` приложение
 * решает, тянуть ли каталог заново.
 */
final class SyncResult
{
    public function __construct(
        public readonly int $catalogVersion,
        public readonly int $previousVersion,
        public readonly int $lag,
    ) {}

    public function upToDate(): bool
    {
        return $this->lag === 0;
    }
}
