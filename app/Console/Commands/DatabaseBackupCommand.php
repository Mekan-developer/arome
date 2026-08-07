<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Снимает дамп PostgreSQL в storage/app/backups и подчищает старые копии.
 * Вся работа с процессами и файлами живёт в DatabaseBackupService.
 */
#[Signature('db:backup {--keep= : Сколько последних дампов оставить} {--path= : Папка для дампов вместо диска backups}')]
#[Description('Дамп базы через pg_dump с gzip и ротацией старых копий')]
class DatabaseBackupCommand extends Command
{
    public function handle(DatabaseBackupService $backups): int
    {
        $keep = $this->option('keep');

        if ($keep !== null && (! ctype_digit((string) $keep) || (int) $keep < 1)) {
            $this->components->error('Значение --keep должно быть целым числом больше нуля.');

            return self::FAILURE;
        }

        $result = $backups->run($keep === null ? null : (int) $keep, $this->option('path'));

        if (! $result['ok']) {
            $this->components->error($result['error']);

            return self::FAILURE;
        }

        $this->components->info("Дамп «{$result['filename']}» создан.");
        $this->components->twoColumnDetail('Размер', $this->humanSize($result['size']));
        $this->components->twoColumnDetail('Удалено старых дампов', (string) $result['deleted']);

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $power = $bytes > 0 ? (int) min(floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
