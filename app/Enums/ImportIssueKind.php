<?php

namespace App\Enums;

/**
 * Что проверка нашла в строке файла. Раскраска строки и тег в колонке «Что не так»
 * выводятся отсюда, а не задаются в шаблоне.
 */
enum ImportIssueKind: string
{
    case Ok = 'ok';
    case Warning = 'warn';
    case Error = 'err';
    case Fixed = 'fixed';

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'transparent',
            self::Warning => 'var(--warn)',
            self::Error => 'var(--danger)',
            self::Fixed => 'var(--ok)',
        };
    }

    public function background(): string
    {
        return match ($this) {
            self::Ok => 'transparent',
            self::Warning => 'var(--tint-warn)',
            self::Error => 'var(--danger-tint)',
            self::Fixed => 'var(--tint-fixed)',
        };
    }

    /**
     * Строки с ошибками не импортируются; предупреждения проходят.
     */
    public function blocksImport(): bool
    {
        return $this === self::Error;
    }
}
