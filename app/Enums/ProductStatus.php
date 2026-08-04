<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case Hidden = 'hidden';

    /**
     * Подпись статуса в таблице и в карточке.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'В ПРОДАЖЕ',
            self::Hidden => 'СКРЫТ',
        };
    }

    /**
     * Цвет статуса — один и тот же в списке, в фильтре и в карточке.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'var(--ok)',
            self::Hidden => 'var(--ink-3)',
        };
    }

    /**
     * Подсказка под селектом статуса в модалке товара.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Active => 'Товар выдаётся устройствам при синхронизации и доступен для продажи.',
            self::Hidden => 'Карточка остаётся в базе и в отчётах, но на кассу и в приложение не попадает.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
