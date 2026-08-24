<?php

namespace App\Services;

use App\Models\RoleFieldRight;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which card fields reach which role. A hidden field is not stripped on screen — it
 * never leaves the server, so it cannot be recovered by intercepting the traffic.
 */
class RightsService
{
    private const CACHE_KEY = 'aroma.rights';

    /**
     * The eight card fields, with the preview sample shown in the right-hand column.
     *
     * @var list<array{key: string, title: string, sample: string}>
     */
    public const FIELDS = [
        ['key' => 'mainCode', 'title' => 'Основной код', 'sample' => 'AA1001'],
        ['key' => 'name', 'title' => 'Номенклатура', 'sample' => 'VERSACE BRIGHT CRYSTAL EDT 30ML'],
        ['key' => 'sku', 'title' => 'Артикул', 'sample' => '510028'],
        ['key' => 'barcode', 'title' => 'Штрихкод', 'sample' => '8011003993802'],
        ['key' => 'stock', 'title' => 'Остаток', 'sample' => 'БРК 12 · ГЛС 4 · М30 0'],
        ['key' => 'retail', 'title' => 'Розничная цена', 'sample' => '1 415,88 TMT'],
        ['key' => 'wholesale', 'title' => 'Оптовая цена', 'sample' => '920,00 TMT'],
        ['key' => 'discount', 'title' => 'Скидка и цена со скидкой', 'sample' => '50 % · 757,62 TMT'],
    ];

    /**
     * Fields withheld from the rights matrix for now. The policy row keeps living in the
     * database and still filters the API, so a column returns by dropping its key here.
     *
     * @var list<string>
     */
    private const HIDDEN_IN_PANEL = ['stock'];

    /**
     * @var list<array{key: string, title: string, note: string, editable: bool}>
     */
    public const ROLES = [
        ['key' => 'admin', 'title' => 'Администратор', 'note' => 'Веб-панель: каталог, цены, импорт, доступы. Менять нельзя', 'editable' => false],
        ['key' => 'manager', 'title' => 'Менеджер', 'note' => 'Мобильное приложение, как у продавца, но с оптовой ценой', 'editable' => true],
        ['key' => 'seller', 'title' => 'Продавец', 'note' => 'Только мобильное приложение — данные из этой панели по API', 'editable' => true],
    ];

    /**
     * Поля, у которых политика по умолчанию не «видно всем». Оптовая цена — граница
     * между менеджером и продавцом: пока строки в базе нет, продавец её не получает.
     *
     * @var array<string, array<string, bool>>
     */
    private const DEFAULTS = [
        'seller' => ['wholesale' => false],
    ];

    /**
     * The fields the panel offers for switching.
     *
     * @return list<array{key: string, title: string, sample: string}>
     */
    public static function panelFields(): array
    {
        return array_values(array_filter(
            self::FIELDS,
            fn (array $field): bool => ! in_array($field['key'], self::HIDDEN_IN_PANEL, true),
        ));
    }

    /**
     * Effective policy as role => field => visible.
     *
     * @return array<string, array<string, bool>>
     */
    public function matrix(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $matrix = [];

            foreach (self::ROLES as $role) {
                foreach (self::FIELDS as $field) {
                    $matrix[$role['key']][$field['key']] = self::DEFAULTS[$role['key']][$field['key']] ?? true;
                }
            }

            foreach (RoleFieldRight::all(['role', 'field', 'visible']) as $right) {
                $matrix[$right->role][$right->field] = $right->visible;
            }

            // The administrator role is immutable: every field is always visible.
            foreach (self::FIELDS as $field) {
                $matrix['admin'][$field['key']] = true;
            }

            return $matrix;
        });
    }

    /**
     * Fields a role may receive.
     *
     * @return list<string>
     */
    public function visibleFields(string $role): array
    {
        $matrix = $this->matrix();

        if (! isset($matrix[$role])) {
            return array_column(self::FIELDS, 'key');
        }

        return array_keys(array_filter($matrix[$role]));
    }

    /**
     * Роли, строку которых панель разрешает править. Администратор в список не входит:
     * ему поля карточки видны всегда.
     *
     * @return list<string>
     */
    public static function editableRoles(): array
    {
        return array_values(array_map(
            fn (array $role): string => $role['key'],
            array_filter(self::ROLES, fn (array $role): bool => $role['editable']),
        ));
    }

    /**
     * Persist the policy of the editable roles. The admin row is ignored on purpose —
     * it cannot change.
     *
     * @param  array<string, array<string, bool>>  $policy  роль => поле => видно
     */
    public function save(array $policy, string $actor, AuditService $audit): void
    {
        DB::transaction(function () use ($policy, $actor, $audit): void {
            foreach (self::editableRoles() as $role) {
                $this->saveRole($role, $policy[$role] ?? [], $actor, $audit);
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, bool>  $fields
     */
    private function saveRole(string $role, array $fields, string $actor, AuditService $audit): void
    {
        foreach (self::FIELDS as $field) {
            $key = $field['key'];

            if (! array_key_exists($key, $fields)) {
                continue;
            }

            $right = RoleFieldRight::firstOrNew(['role' => $role, 'field' => $key]);
            $was = $right->exists ? $right->visible : (self::DEFAULTS[$role][$key] ?? true);
            $now = (bool) $fields[$key];

            if ($was !== $now) {
                $audit->record($actor, 'Права роли изменены', self::roleTitle($role).' · '.$field['title'], $was ? 'видно' : 'скрыто', $now ? 'видно' : 'скрыто', 'rights');
            }

            $right->visible = $now;
            $right->save();
        }
    }

    private static function roleTitle(string $role): string
    {
        foreach (self::ROLES as $entry) {
            if ($entry['key'] === $role) {
                return $entry['title'];
            }
        }

        return $role;
    }
}
