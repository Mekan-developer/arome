<?php

namespace App\Enums;

enum ModuleKey: string
{
    case Points = 'points';
    case Warehouses = 'warehouses';
    case ProductPoints = 'productPoints';
    case Import = 'import';
    case Devices = 'devices';
    case Audit = 'audit';

    public function label(): string
    {
        return match ($this) {
            self::Points => 'Точки продаж',
            self::Warehouses => 'Склады',
            self::ProductPoints => 'Остатки товара по точкам',
            self::Import => 'Импорт из Excel',
            self::Devices => 'Синхронизация устройств',
            self::Audit => 'Журнал действий',
        };
    }

    /**
     * Модуль, без которого этот не работает.
     */
    public function dependsOn(): ?self
    {
        return match ($this) {
            self::Warehouses, self::ProductPoints => self::Points,
            default => null,
        };
    }

    /**
     * Состояние по умолчанию — как модуль отдаётся заказчику.
     */
    public function defaultEnabled(): bool
    {
        return match ($this) {
            self::Points, self::Warehouses, self::ProductPoints => false,
            default => true,
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
