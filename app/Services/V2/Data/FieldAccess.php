<?php

namespace App\Services\V2\Data;

use App\Services\V2\FieldAccessService;

/**
 * Что именно роль имеет право получить: состав колонок карточки, остаток по точкам и
 * колонки, по которым вообще разрешено искать.
 *
 * Считается один раз на запрос {@see FieldAccessService} и дальше
 * едет через сервис в ресурс: и выборка, и ответ смотрят в один и тот же объект,
 * поэтому «искать можно, а видеть нельзя» здесь не выражается в принципе.
 */
final class FieldAccess
{
    /**
     * @param  list<string>  $visibleFields  ключи матрицы прав: name, sku, mainCode, …
     * @param  list<string>  $searchColumns  колонки таблицы, открытые для `?q=`
     */
    public function __construct(
        public readonly array $visibleFields,
        public readonly bool $withStock,
        public readonly array $searchColumns,
    ) {}

    public function sees(string $field): bool
    {
        return in_array($field, $this->visibleFields, true);
    }
}
