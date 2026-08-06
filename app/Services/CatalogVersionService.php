<?php

namespace App\Services;

use App\Models\CatalogVersion;
use Illuminate\Support\Facades\Cache;

/**
 * Счётчик ревизий каталога. Одна правка цены, скрытие товара или загрузка прайса —
 * это одна ревизия, а не одна ревизия на каждый затронутый SKU: телефон сравнивает
 * свою отметку с этим числом и по разнице понимает, сколько правок он не видел.
 *
 * Читается на каждой синхронизации и на каждом открытии раздела «Синхронизация»,
 * поэтому лежит в кеше — как и флаги модулей.
 */
class CatalogVersionService
{
    private const CACHE_KEY = 'aroma.catalog_version';

    /**
     * Ревизия, опубликованная на телефоны прямо сейчас. Пустая таблица — это ревизия 0,
     * то есть пустой каталог: телефон с такой же отметкой ничего не пропустил.
     */
    public function current(): int
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn (): int => (int) (CatalogVersion::query()->value('number') ?? 0),
        );
    }

    /**
     * Каталог изменился. Инкремент идёт одним SQL-запросом, а не чтением с записью:
     * два администратора, правящие цены одновременно, иначе затрут ревизию друг друга.
     *
     * Строку сначала восстанавливаем: базу могли очистить целиком, а инкремент по
     * пустой таблице молча ничего не сделал бы и счётчик застрял бы на нуле.
     */
    public function bump(): int
    {
        CatalogVersion::query()->firstOrCreate([], ['number' => 0])->increment('number');

        Cache::forget(self::CACHE_KEY);

        return $this->current();
    }
}
