<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'login', 'role', 'password', 'is_active', 'last_login_at', 'device'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_root' => 'boolean',
            'last_login_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    /**
     * @return BelongsToMany<Point, $this>
     */
    public function points(): BelongsToMany
    {
        return $this->belongsToMany(Point::class);
    }

    /**
     * @return HasOne<Device, $this>
     */
    public function deviceRecord(): HasOne
    {
        return $this->hasOne(Device::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isSuperadmin(): bool
    {
        return $this->role === UserRole::Superadmin;
    }

    public function isSeller(): bool
    {
        return $this->role === UserRole::Seller;
    }

    /**
     * Кто работает в поиске товара: продавец и менеджер. Менеджеру открыта ещё и панель,
     * а от продавца его отличает оптовая цена — её решает матрица прав, не этот метод.
     */
    public function usesSellerPortal(): bool
    {
        return in_array($this->role, [UserRole::Seller, UserRole::Manager], true);
    }

    /**
     * Корневой администратор — учётка из ADMIN_LOGIN, с которой начинается установка.
     * Флаг не входит в Fillable: его ставит только сидер, из панели его не выдать.
     */
    public function isRootAdmin(): bool
    {
        return $this->isAdmin() && $this->is_root;
    }

    /**
     * Кто раздаёт доступы: корневой администратор и суперадмин над ним. Обычный
     * администратор ведёт каталог, но раздела «Пользователи» не видит.
     */
    public function managesStaff(): bool
    {
        return $this->isRootAdmin() || $this->isSuperadmin();
    }

    /**
     * Кто ведёт каталог: администратор в панели и суперадмин, смотрящий её глазами.
     */
    public function managesCatalog(): bool
    {
        return $this->isAdmin() || $this->isSuperadmin();
    }
}
