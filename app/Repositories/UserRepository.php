<?php

namespace App\Repositories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    /**
     * Staff list for the section. The superadmin never appears here — the role is
     * hidden from the employee list, the rights matrix and the access journal.
     *
     * @return Collection<int, User>
     */
    public function staff(bool $withPoints): Collection
    {
        return User::query()
            ->select(['id', 'name', 'login', 'role', 'is_active', 'last_login_at', 'device'])
            ->whereIn('role', UserRole::assignableValues())
            ->when($withPoints, fn ($query) => $query->with('points:id,code,name,address'))
            ->orderBy('id')
            ->get();
    }

    public function loginExists(string $login, ?int $exceptId = null): bool
    {
        return User::where('login', $login)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function findByLogin(string $login): ?User
    {
        return User::where('login', $login)->first();
    }
}
