<?php

namespace App\Policies;

use App\Models\Module;
use App\Models\User;

/**
 * Модулями управляют только из служебной консоли — и только суперадмин.
 */
class ModulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function toggle(User $user, ?Module $module = null): bool
    {
        return $user->isSuperadmin();
    }

    /**
     * «Открыть панель администратора» — просмотр чужими глазами.
     */
    public function impersonate(User $user): bool
    {
        return $user->isSuperadmin();
    }
}
