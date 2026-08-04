<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Seller = 'seller';
    case Superadmin = 'superadmin';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::Seller => 'Продавец',
            self::Superadmin => 'Суперадмин',
        };
    }

    /**
     * Роли, которые видно в списке сотрудников и в матрице прав. «Суперадмин» скрыт
     * везде — это часть модели доступа, а не оформление.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Seller];
    }

    /**
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return array_map(fn (self $role): string => $role->value, self::assignable());
    }
}
