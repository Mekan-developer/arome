<?php

namespace App\Services\V2;

use App\Enums\ModuleKey;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\RightsService;
use App\Services\V2\Data\FieldAccess;

/**
 * Права роли на поля карточки, собранные в один объект на запрос.
 *
 * Матрица прав и флаги модулей — общие для панели и для API, поэтому читаются
 * общими сервисами: собственная копия матрицы у v2 означала бы, что «скрыть оптовую
 * цену» в панели однажды перестанет доходить до телефона.
 */
class FieldAccessService
{
    /**
     * Ключ матрицы прав => колонка, по которой ищет `?q=`.
     *
     * @var array<string, string>
     */
    private const SEARCHABLE = [
        'name' => 'name',
        'sku' => 'sku',
        'mainCode' => 'main_code',
        'barcode' => 'barcode',
    ];

    public function __construct(
        private readonly RightsService $rights,
        private readonly ModuleService $modules,
    ) {}

    /**
     * Роль неизвестна — считаем продавцом: это самый узкий набор полей, и ошибка в
     * эту сторону ничего не раскрывает.
     */
    public function forUser(?User $user): FieldAccess
    {
        $visible = $this->rights->visibleFields($user?->role->value ?? 'seller');

        return new FieldAccess(
            visibleFields: $visible,
            withStock: $this->withStock($visible),
            searchColumns: $this->searchColumns($visible),
        );
    }

    /**
     * Остаток уходит на устройство, только если он и разбит по точкам, и открыт роли.
     *
     * @param  list<string>  $visible
     */
    private function withStock(array $visible): bool
    {
        return $this->modules->enabled(ModuleKey::ProductPoints->value) && in_array('stock', $visible, true);
    }

    /**
     * Искать можно только по тем полям, которые роль и так видит в карточке — иначе
     * скрытое поле восстанавливается перебором префиксов через `?q=`.
     *
     * @param  list<string>  $visible
     * @return list<string>
     */
    private function searchColumns(array $visible): array
    {
        return array_values(array_intersect_key(self::SEARCHABLE, array_flip($visible)));
    }
}
