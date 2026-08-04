<?php

namespace App\Enums;

enum AuditKind: string
{
    case Auth = 'auth';
    case Price = 'price';
    case Stock = 'stock';
    case Import = 'import';
    case Rights = 'rights';
    case Product = 'product';
    case User = 'user';
    case Point = 'point';

    public function label(): string
    {
        return match ($this) {
            self::Auth => 'ВХОД',
            self::Price => 'ЦЕНА',
            self::Stock => 'ОСТАТОК',
            self::Import => 'ИМПОРТ',
            self::Rights => 'ПРАВА',
            self::Product => 'ТОВАР',
            self::User => 'СОТРУДНИК',
            self::Point => 'ТОЧКА',
        };
    }

    /**
     * Цвет подчёркивания типа события в журнале.
     */
    public function color(): string
    {
        return match ($this) {
            self::Price => 'var(--brass-dark)',
            self::Stock, self::Product => 'var(--ink-2)',
            self::Rights, self::User => 'var(--danger)',
            self::Import, self::Point => 'var(--ok)',
            self::Auth => 'var(--ink-3)',
        };
    }

    /**
     * Значения селекта «Тип события» -> какие виды событий он показывает.
     *
     * @return array<string, list<string>>
     */
    public static function filters(): array
    {
        return [
            'all' => [],
            'price' => [self::Price->value],
            'rights' => [self::Rights->value],
            'users' => [self::User->value],
            'products' => [self::Product->value],
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
