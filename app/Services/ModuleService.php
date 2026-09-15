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
     * Copy shown in the service console.
     *
     * @var array<string, array{title: string, description: string, affects: list<string>}>
     */
    public const CATALOG = [
        'points' => [
            'title' => 'Точки продаж',
            'description' => 'Сеть ведётся как несколько торговых точек: свой адрес, свой персонал, свой остаток. Выключено — администратор работает с одним общим каталогом.',
            'affects' => ['Раздел «Точки и склады»', 'Фильтр «Точка» в товарах', 'Колонка «Точки» у сотрудников', 'Выбор точек в карточке сотрудника', 'Точка в синхронизации'],
        ],
        'warehouses' => [
            'title' => 'Склады',
            'description' => 'Склад — точка без продаж: участвует в остатках, но не в кассе. Выключено — в списках остаются только торговые точки.',
            'affects' => ['Строки складов в списке точек', 'Колонки складов в остатках', 'Статус «СКЛАД»'],
        ],
        'productPoints' => [
            'title' => 'Остатки товара по точкам',
            'description' => 'Карточка товара хранит остаток отдельно по каждой точке. Выключено — у товара один общий остаток по сети.',
            'affects' => ['Колонки остатков по точкам в таблице', 'Блок «Остаток по точкам» в карточке', 'Стартовые остатки в новом товаре', 'Действие «Перевести на точку»'],
        ],
        'import' => [
            'title' => 'Импорт из Excel',
            'description' => 'Загрузка прайса файлом с сопоставлением колонок и проверкой строк.',
            'affects' => ['Раздел «Импорт»', 'Кнопка «Импорт из Excel» в товарах'],
        ],
        'devices' => [
            'title' => 'Синхронизация устройств',
            'description' => 'Контроль того, какие телефоны продавцов давно не получали актуальный каталог.',
            'affects' => ['Раздел «Синхронизация»'],
        ],
        'audit' => [
            'title' => 'Журнал действий',
            'description' => 'Неизменяемая история действий в панели. Запись ведётся в любом случае — флаг скрывает только раздел.',
            'affects' => ['Раздел «Журнал действий»'],
        ],
    ];

    /**
     * Разделы панели. `access` — кто видит пункт после входа:
     * search — веб-поиск/сканер, panel — каталог и настройки, staff — сотрудники, any — всем.
     *
     * @var list<array{key: string, title: string, href: string, module: string|null, access: string, external: bool}>
     */
    public const SECTIONS = [
        ['key' => 'search', 'title' => 'Поиск', 'href' => '/search', 'module' => null, 'access' => 'search', 'external' => false],
        ['key' => 'products', 'title' => 'Товары', 'href' => '/products', 'module' => null, 'access' => 'panel', 'external' => false],
        ['key' => 'import', 'title' => 'Импорт', 'href' => '/import', 'module' => 'import', 'access' => 'panel', 'external' => false],
        ['key' => 'users', 'title' => 'Пользователи', 'href' => '/users', 'module' => null, 'access' => 'staff', 'external' => false],
        ['key' => 'rights', 'title' => 'Матрица прав', 'href' => '/rights', 'module' => null, 'access' => 'panel', 'external' => false],
        ['key' => 'points', 'title' => 'Точки и склады', 'href' => '/points', 'module' => 'points', 'access' => 'panel', 'external' => false],
        ['key' => 'devices', 'title' => 'Синхронизация', 'href' => '/devices', 'module' => 'devices', 'access' => 'panel', 'external' => false],
        ['key' => 'audit', 'title' => 'Журнал действий', 'href' => '/audit', 'module' => 'audit', 'access' => 'panel', 'external' => false],
        // База знаний — отдельный статический сайт со своей шапкой и кнопкой
        // возврата, а не страница Inertia: вкладка уводит из SPA целиком.
        ['key' => 'lessons', 'title' => 'Обучение', 'href' => '/lessons', 'module' => null, 'access' => 'any', 'external' => true],
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
     * Разделы, которые зритель реально видит: модули режут флаги, роль — access.
     *
     * @return list<array{key: string, title: string, href: string, module: string|null, access: string, external: bool, visible: bool}>
     */
    public function sections(?User $viewer): array
    {
        $effective = $this->effective();

        return array_map(fn (array $section): array => $section + [
            'visible' => $this->sectionVisible($section, $viewer, $effective),
        ], self::SECTIONS);
    }

    /**
     * @param  array{key: string, title: string, href: string, module: string|null, access: string, external: bool}  $section
     * @param  array<string, bool>  $effective
     */
    private function sectionVisible(array $section, ?User $viewer, array $effective): bool
    {
        if ($section['module'] !== null && ! ($effective[$section['module']] ?? false)) {
            return false;
        }

        return match ($section['access']) {
            'search' => (bool) $viewer?->usesSearch(),
            'panel' => (bool) $viewer?->usesPanel(),
            'staff' => (bool) $viewer?->managesStaff(),
            'any' => $viewer !== null,
            default => false,
        };
    }

    /**
     * Module cards for the service console, with dependency state resolved.
     *
     * @return list<array{key: string, title: string, description: string, affects: list<string>, isEnabled: bool, effective: bool, blockedBy: string|null}>
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
