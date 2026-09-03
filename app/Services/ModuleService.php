<?php

namespace App\Services;

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Feature flags. A disabled module strips whole sections, filters, columns and form
 * fields out of the admin panel while the rows stay untouched in the database.
 */
class ModuleService
{
    private const CACHE_KEY = 'aroma.modules';

    /**
     * Copy shown in the service console, dictated word for word by the specification.
     *
     * @var array<string, array{title: string, description: string, affects: list<string>, kept: string}>
     */
    public const CATALOG = [
        'points' => [
            'title' => 'Точки продаж',
            'description' => 'Сеть ведётся как несколько торговых точек: свой адрес, свой персонал, свой остаток. Выключено — администратор работает с одним общим каталогом.',
            'affects' => ['Раздел «Точки и склады»', 'Фильтр «Точка» в товарах', 'Колонка «Точки» у сотрудников', 'Выбор точек в карточке сотрудника', 'Точка в синхронизации'],
            'kept' => '4 точки, 9 привязок сотрудников',
        ],
        'warehouses' => [
            'title' => 'Склады',
            'description' => 'Склад — точка без продаж: участвует в остатках, но не в кассе. Выключено — в списках остаются только торговые точки.',
            'affects' => ['Строка «Склад Чоганлы»', 'Колонка СКЛ в остатках', 'Статус «СКЛАД»'],
            'kept' => '1 склад, 3 760 SKU на остатке',
        ],
        'productPoints' => [
            'title' => 'Остатки товара по точкам',
            'description' => 'Карточка товара хранит остаток отдельно по каждой точке. Выключено — у товара один общий остаток по сети.',
            'affects' => ['Колонки БРК / ГЛС / М30 в таблице', 'Блок «Остаток по точкам» в карточке', 'Стартовые остатки в новом товаре', 'Действие «Перевести на точку»'],
            'kept' => '12 480 записей остатков',
        ],
        'import' => [
            'title' => 'Импорт из Excel',
            'description' => 'Загрузка прайса файлом с сопоставлением колонок и проверкой строк.',
            'affects' => ['Раздел «Импорт»', 'Кнопка «Импорт из Excel» в товарах'],
            'kept' => '18 загрузок в истории',
        ],
        'devices' => [
            'title' => 'Синхронизация устройств',
            'description' => 'Контроль того, какие телефоны продавцов давно не получали актуальный каталог.',
            'affects' => ['Раздел «Синхронизация»'],
            'kept' => '6 устройств',
        ],
        'audit' => [
            'title' => 'Журнал действий',
            'description' => 'Неизменяемая история действий в панели. Запись ведётся в любом случае — флаг скрывает только раздел.',
            'affects' => ['Раздел «Журнал действий»'],
            'kept' => '24 месяца записей',
        ],
    ];

    /**
     * Panel sections in tab order. `module` names the flag that hides the section,
     * `staffOnly` marks the one section closed by role instead of by a flag,
     * `external` — the tab that leaves the SPA and is followed by a plain link.
     *
     * @var list<array{key: string, title: string, href: string, module: string|null, staffOnly: bool, external: bool}>
     */
    public const SECTIONS = [
        ['key' => 'products', 'title' => 'Товары', 'href' => '/products', 'module' => null, 'staffOnly' => false, 'external' => false],
        ['key' => 'import', 'title' => 'Импорт', 'href' => '/import', 'module' => 'import', 'staffOnly' => false, 'external' => false],
        ['key' => 'users', 'title' => 'Пользователи', 'href' => '/users', 'module' => null, 'staffOnly' => true, 'external' => false],
        ['key' => 'rights', 'title' => 'Матрица прав', 'href' => '/rights', 'module' => null, 'staffOnly' => false, 'external' => false],
        ['key' => 'points', 'title' => 'Точки и склады', 'href' => '/points', 'module' => 'points', 'staffOnly' => false, 'external' => false],
        ['key' => 'devices', 'title' => 'Синхронизация', 'href' => '/devices', 'module' => 'devices', 'staffOnly' => false, 'external' => false],
        ['key' => 'audit', 'title' => 'Журнал действий', 'href' => '/audit', 'module' => 'audit', 'staffOnly' => false, 'external' => false],
        // База знаний — отдельный статический сайт со своей шапкой и кнопкой
        // возврата, а не страница Inertia: вкладка уводит из SPA целиком.
        ['key' => 'lessons', 'title' => 'Обучение', 'href' => '/lessons', 'module' => null, 'staffOnly' => false, 'external' => true],
    ];

    /**
     * Flags and dependencies in one cached read — resolving a tree of six modules
     * must not cost a query per node.
     *
     * @return array<string, array{enabled: bool, parent: string|null}>
     */
    public function meta(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => Module::all(['key', 'is_enabled', 'depends_on'])
            ->mapWithKeys(fn (Module $module): array => [
                $module->key => ['enabled' => $module->is_enabled, 'parent' => $module->depends_on],
            ])
            ->all());
    }

    /**
     * Raw flags as stored, ignoring dependencies.
     *
     * @return array<string, bool>
     */
    public function raw(): array
    {
        return array_map(fn (array $module): bool => $module['enabled'], $this->meta());
    }

    /**
     * Effective flags: a module counts as on only when it and every ancestor is on.
     *
     * @return array<string, bool>
     */
    public function effective(): array
    {
        $meta = $this->meta();
        $effective = [];

        foreach (array_keys(self::CATALOG) as $key) {
            $effective[$key] = $this->resolve($key, $meta);
        }

        return $effective;
    }

    public function enabled(string $key): bool
    {
        return $this->effective()[$key] ?? false;
    }

    /**
     * Flip a flag and drop the cache.
     */
    public function toggle(string $key): bool
    {
        $module = Module::where('key', $key)->firstOrFail();
        $module->update(['is_enabled' => ! $module->is_enabled]);

        Cache::forget(self::CACHE_KEY);

        return $module->is_enabled;
    }

    /**
     * Sections the viewer can actually reach right now: the flags decide most of the
     * strip, the role decides «Пользователи».
     *
     * @return list<array{key: string, title: string, href: string, module: string|null, staffOnly: bool, external: bool, visible: bool}>
     */
    public function sections(?User $viewer): array
    {
        $effective = $this->effective();

        return array_map(fn (array $section): array => $section + [
            'visible' => ($section['module'] === null || ($effective[$section['module']] ?? false))
                && (! $section['staffOnly'] || (bool) $viewer?->managesStaff()),
        ], self::SECTIONS);
    }

    /**
     * Module cards for the service console, with dependency state resolved.
     *
     * @return list<array{key: string, title: string, description: string, affects: list<string>, kept: string, isEnabled: bool, effective: bool, blockedBy: string|null}>
     */
    public function cards(): array
    {
        $meta = $this->meta();
        $cards = [];

        foreach (self::CATALOG as $key => $copy) {
            $parent = $meta[$key]['parent'] ?? null;
            $blocked = $parent !== null && ! $this->resolve($parent, $meta);

            $cards[] = [
                'key' => $key,
                'title' => $copy['title'],
                'description' => $copy['description'],
                'affects' => $copy['affects'],
                'kept' => $copy['kept'],
                'isEnabled' => $meta[$key]['enabled'] ?? false,
                'effective' => $this->resolve($key, $meta),
                'blockedBy' => $blocked ? self::CATALOG[$parent]['title'] : null,
            ];
        }

        return $cards;
    }

    /**
     * A module is on only when it and every ancestor is on. The depth guard keeps a
     * mis-seeded dependency cycle from turning into infinite recursion.
     *
     * @param  array<string, array{enabled: bool, parent: string|null}>  $meta
     */
    private function resolve(string $key, array $meta, int $depth = 0): bool
    {
        if ($depth > 10 || ! ($meta[$key]['enabled'] ?? false)) {
            return false;
        }

        $parent = $meta[$key]['parent'] ?? null;

        return $parent === null || $this->resolve($parent, $meta, $depth + 1);
    }
}
