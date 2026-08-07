<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Дамп PostgreSQL в локальную папку плюс ротация старых копий.
 *
 * Дамп забирается со stdout `pg_dump` и жмётся gzip уже в PHP: так результат не
 * зависит от того, какие методы сжатия собраны в конкретном клиенте, и тем же
 * кодом пишется как настоящий файл, так и подменённый диск в тестах.
 */
class DatabaseBackupService
{
    /**
     * Полный прогон: дамп, проверка результата и — только при успехе — ротация.
     *
     * @return array{ok: bool, filename: ?string, size: int, deleted: int, error: ?string}
     */
    public function run(?int $keep = null, ?string $path = null): array
    {
        $keep = $keep ?? (int) config('backup.keep');
        $disk = $this->disk($path);

        $dump = $this->dump($disk);

        if (! $dump['ok']) {
            Log::error('Бэкап БД провалился.', [
                'filename' => $dump['filename'],
                'error' => $dump['error'],
            ]);

            return $dump + ['deleted' => 0];
        }

        $deleted = $this->rotate($disk, $keep);

        Log::info('Бэкап БД создан.', [
            'filename' => $dump['filename'],
            'bytes' => $dump['size'],
            'deleted' => $deleted,
            'keep' => $keep,
        ]);

        return $dump + ['deleted' => $deleted];
    }

    /**
     * Снимает дамп и кладёт его на диск. Битый результат подчищается за собой,
     * чтобы обрезанный файл не сошёл за рабочую копию при следующей ротации.
     *
     * @return array{ok: bool, filename: ?string, size: int, error: ?string}
     */
    private function dump(Filesystem $disk): array
    {
        $connection = $this->connection();

        if ($connection['driver'] !== 'pgsql') {
            return [
                'ok' => false,
                'filename' => null,
                'size' => 0,
                'error' => "Соединение «{$this->connectionName()}» использует драйвер «{$connection['driver']}», а дамп умеет только pgsql.",
            ];
        }

        $filename = $this->filename($connection);

        $result = Process::env($this->environment($connection))
            ->timeout(max(300, (int) config('backup.timeout')))
            ->run($this->command($connection));

        if ($result->failed()) {
            return [
                'ok' => false,
                'filename' => $filename,
                'size' => 0,
                'error' => sprintf(
                    'pg_dump вернул код %d: %s',
                    $result->exitCode(),
                    trim($result->errorOutput()) ?: 'stderr пуст',
                ),
            ];
        }

        $disk->put($filename, gzencode($result->output(), 9));

        $size = (int) $disk->size($filename);
        $minimum = (int) config('backup.min_bytes');

        if ($size < $minimum) {
            $disk->delete($filename);

            return [
                'ok' => false,
                'filename' => $filename,
                'size' => $size,
                'error' => "Дамп весит {$size} Б при пороге {$minimum} Б — файл удалён как битый.",
            ];
        }

        return ['ok' => true, 'filename' => $filename, 'size' => $size, 'error' => null];
    }

    /**
     * Удаляет всё, кроме последних $keep дампов. Сортировка по имени файла —
     * формат даты для этого и выбран, mtime врёт после копирования папки.
     */
    private function rotate(Filesystem $disk, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $backups = $this->existing($disk);

        $stale = $backups->slice(0, max(0, $backups->count() - $keep));

        foreach ($stale as $file) {
            $disk->delete($file);
        }

        return $stale->count();
    }

    /**
     * Файлы папки, подходящие под маску имени бэкапа, по возрастанию даты.
     * Посторонние файлы отсеиваются и под ротацию никогда не попадают.
     *
     * @return Collection<int, string>
     */
    private function existing(Filesystem $disk): Collection
    {
        $mask = '/^'.preg_quote($this->prefix(), '/').'_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/';

        return (new Collection($disk->files()))
            ->filter(fn (string $file): bool => (bool) preg_match($mask, basename($file)))
            ->sort()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<int, string>
     */
    private function command(array $connection): array
    {
        return [
            (string) config('backup.pg_dump'),
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--username='.$connection['username'],
            '--dbname='.$connection['database'],
            '--no-owner',
            '--no-privileges',
            '--clean',
            '--if-exists',
        ];
    }

    /**
     * Пароль уходит в окружение процесса, а не в аргументы: строка запуска
     * видна в `ps` любому пользователю контейнера, окружение — нет.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, string>
     */
    private function environment(array $connection): array
    {
        return array_filter([
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            'PGSSLMODE' => (string) ($connection['sslmode'] ?? ''),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function filename(array $connection): string
    {
        $now = CarbonImmutable::now();

        return sprintf('%s_%s.sql.gz', $this->prefix($connection), $now->format('Y-m-d_His'));
    }

    /**
     * @param  array<string, mixed>|null  $connection
     */
    private function prefix(?array $connection = null): string
    {
        $prefix = config('backup.prefix');

        if (blank($prefix)) {
            $connection ??= $this->connection();
            $prefix = $connection['database'] ?? 'database';
        }

        return (string) $prefix;
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(): array
    {
        return (array) config('database.connections.'.$this->connectionName());
    }

    private function connectionName(): string
    {
        return (string) (config('backup.connection') ?: config('database.default'));
    }

    /**
     * Диск с дампами: настроенный по умолчанию либо одноразовый с корнем из --path.
     */
    private function disk(?string $path): Filesystem
    {
        if (blank($path)) {
            return Storage::disk(config('backup.disk'));
        }

        return Storage::build([
            'driver' => 'local',
            'root' => $path,
            'throw' => true,
        ]);
    }
}
