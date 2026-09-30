<?php

namespace App\Policies;

use App\Models\User;

/**
 * Раздел «Пользователи» закрыт не модулем, а ролью: доступы раздаёт любой
 * администратор и суперадмин. Менеджер и зал раздела не видят.
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

    /**
     * Удаление — право строже прочих: его не дают даже суперадмину, только роли
     * «Администратор». Суперадмина и себя самого удалить нельзя по тем же причинам,
     * что и в manageAccess().
     */
    public function delete(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $target->isSuperadmin() && $user->isNot($target);
    }
}
