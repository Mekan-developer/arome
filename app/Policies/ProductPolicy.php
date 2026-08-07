<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Каталог ведут только из веб-панели. Продавец получает карточки по API и не может
 * их менять ни при каких настройках матрицы прав.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->managesCatalog();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->managesCatalog();
    }

    /**
     * Удаление карточки из каталога — вместе с остатками, историей цены и сканами.
     * Обычный способ убрать товар с продажи — статус «скрыт»; удаление необратимо,
     * поэтому право на него то же, что и на правку каталога, а не шире.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->managesCatalog();
    }

    /**
     * Массовая правка цены и скрытие из продажи.
     */
    public function bulkEdit(User $user): bool
    {
        return $user->managesCatalog();
    }
}
