<?php

namespace App\Policies;

use App\Models\User;

/**
 * Раздел «Пользователи» закрыт не модулем, а ролью: доступы раздаёт корневой
 * администратор (учётка из ADMIN_LOGIN) и суперадмин над ним. Созданные им
 * администраторы ведут каталог, но раздела не видят.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesStaff();
    }

    public function create(User $user): bool
    {
        return $user->managesStaff();
    }

    /**
     * Учётную запись суперадмина не трогает никто: роль скрыта из списка сотрудников,
     * и доступ к ней не выдаётся из панели администратора. Себя тоже не блокируют —
     * иначе единственный владелец раздела запирает сам себя снаружи.
     */
    public function manageAccess(User $user, User $target): bool
    {
        return $user->managesStaff() && ! $target->isSuperadmin() && $user->isNot($target);
    }

    public function changePassword(User $user, User $target): bool
    {
        return $user->managesStaff() && ! $target->isSuperadmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->managesStaff() && ! $target->isSuperadmin();
    }
}
