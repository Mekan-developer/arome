<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');

        config()->set('backup.disk', 'backups');
        config()->set('backup.connection', 'pgsql');
        config()->set('backup.prefix', 'aroma');
        config()->set('backup.keep', 10);
        config()->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => 'db',
            'port' => '5432',
            'database' => 'aroma',
            'username' => 'aroma',
            'password' => 'секрет',
            'sslmode' => 'prefer',
        ]);
    }

    /**
     * Дамп, который и в сжатом виде заметно крупнее порога в 1 КБ: строки должны
     * быть разными, иначе gzip ужмёт повторы до сотни байт и служба сочтёт
     * результат битым.
     */
    private function fakeDump(): void
    {
        $lines = ['-- aroma dump'];

        for ($id = 1; $id <= 400; $id++) {
            $lines[] = sprintf(
                "INSERT INTO products VALUES (%d, '%s', '%s');",
                $id,
                bin2hex(random_bytes(12)),
                bin2hex(random_bytes(12)),
            );
        }

        Process::fake(['*pg_dump*' => Process::result(implode(PHP_EOL, $lines))]);
    }

    /**
     * @param  array<int, string>  $names
     */
    private function seedBackups(array $names): void
    {
        foreach ($names as $name) {
            Storage::disk('backups')->put($name, 'старый дамп');
        }
    }

    public function test_it_writes_a_non_empty_dump(): void
    {
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        $files = Storage::disk('backups')->files();

        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^aroma_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/', $files[0]);
        $this->assertGreaterThan(1024, Storage::disk('backups')->size($files[0]));
    }

    public function test_the_dump_is_gzipped_and_readable(): void
    {
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        $file = Storage::disk('backups')->files()[0];
        $contents = Storage::disk('backups')->get($file);

        $this->assertStringStartsWith("\x1f\x8b", $contents, 'Файл должен начинаться с сигнатуры gzip.');
        $this->assertStringContainsString('-- aroma dump', (string) gzdecode($contents));
    }

    public function test_the_password_travels_in_the_environment_not_in_the_arguments(): void
    {
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRan(function ($process) {
            $this->assertStringNotContainsString('секрет', implode(' ', (array) $process->command));
            $this->assertSame('секрет', $process->environment['PGPASSWORD'] ?? null);
            $this->assertSame('prefer', $process->environment['PGSSLMODE'] ?? null);
            $this->assertGreaterThanOrEqual(300, $process->timeout);

            return true;
        });
    }

    public function test_a_failing_pg_dump_fails_the_command_and_skips_rotation(): void
    {
        $this->seedBackups([
            'aroma_2026-01-01_030000.sql.gz',
            'aroma_2026-01-16_030000.sql.gz',
            'aroma_2026-02-01_030000.sql.gz',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(output: '', errorOutput: 'подключение отклонено', exitCode: 1),
        ]);

        $this->artisan('db:backup', ['--keep' => 1])->assertFailed();

        $this->assertCount(3, Storage::disk('backups')->files(), 'Старые дампы должны пережить неудачный запуск.');
    }

    public function test_an_undersized_dump_counts_as_a_failure_and_is_removed(): void
    {
        Process::fake(['*pg_dump*' => Process::result('-- пусто')]);

        $this->artisan('db:backup')->assertFailed();

        $this->assertSame([], Storage::disk('backups')->files(), 'Битый дамп не должен оставаться на диске.');
    }

    public function test_an_undersized_dump_does_not_trigger_rotation(): void
    {
        $this->seedBackups([
            'aroma_2026-01-01_030000.sql.gz',
            'aroma_2026-01-16_030000.sql.gz',
        ]);

        Process::fake(['*pg_dump*' => Process::result('-- пусто')]);

        $this->artisan('db:backup', ['--keep' => 1])->assertFailed();

        $this->assertCount(2, Storage::disk('backups')->files());
    }

    public function test_rotation_keeps_exactly_the_newest_n_backups(): void
    {
        $this->seedBackups([
            'aroma_2026-01-01_030000.sql.gz',
            'aroma_2026-01-16_030000.sql.gz',
            'aroma_2026-02-01_030000.sql.gz',
            'aroma_2026-02-16_030000.sql.gz',
        ]);

        $this->fakeDump();

        $this->artisan('db:backup', ['--keep' => 3])->assertSuccessful();

        $files = Storage::disk('backups')->files();
        sort($files);

        $this->assertCount(3, $files);
        $this->assertNotContains('aroma_2026-01-01_030000.sql.gz', $files);
        $this->assertNotContains('aroma_2026-01-16_030000.sql.gz', $files);
        $this->assertContains('aroma_2026-02-01_030000.sql.gz', $files);
        $this->assertContains('aroma_2026-02-16_030000.sql.gz', $files);
    }

    public function test_rotation_sorts_by_name_not_by_modification_time(): void
    {
        // Кладём в обратном порядке: по mtime самым старым окажется свежий дамп.
        $this->seedBackups([
            'aroma_2026-03-01_030000.sql.gz',
            'aroma_2026-01-01_030000.sql.gz',
        ]);

        $this->fakeDump();

        $this->artisan('db:backup', ['--keep' => 2])->assertSuccessful();

        $files = Storage::disk('backups')->files();

        $this->assertContains('aroma_2026-03-01_030000.sql.gz', $files);
        $this->assertNotContains('aroma_2026-01-01_030000.sql.gz', $files);
    }

    public function test_rotation_leaves_unrelated_files_alone(): void
    {
        $this->seedBackups([
            'aroma_2026-01-01_030000.sql.gz',
            'aroma_2026-01-16_030000.sql.gz',
        ]);

        Storage::disk('backups')->put('README.md', 'не трогать');
        Storage::disk('backups')->put('aroma_manual.sql.gz', 'ручной дамп');
        Storage::disk('backups')->put('other_2026-01-01_030000.sql.gz', 'чужой префикс');

        $this->fakeDump();

        $this->artisan('db:backup', ['--keep' => 1])->assertSuccessful();

        $this->assertTrue(Storage::disk('backups')->exists('README.md'));
        $this->assertTrue(Storage::disk('backups')->exists('aroma_manual.sql.gz'));
        $this->assertTrue(Storage::disk('backups')->exists('other_2026-01-01_030000.sql.gz'));
        $this->assertFalse(Storage::disk('backups')->exists('aroma_2026-01-01_030000.sql.gz'));
    }

    public function test_keep_defaults_to_the_configured_value(): void
    {
        config()->set('backup.keep', 2);

        $this->seedBackups([
            'aroma_2026-01-01_030000.sql.gz',
            'aroma_2026-01-16_030000.sql.gz',
            'aroma_2026-02-01_030000.sql.gz',
        ]);

        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        $this->assertCount(2, Storage::disk('backups')->files());
    }

    public function test_it_refuses_a_non_postgres_connection(): void
    {
        config()->set('backup.connection', 'sqlite');

        Process::fake();

        $this->artisan('db:backup')->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_rejects_a_nonsense_keep_option(): void
    {
        Process::fake();

        $this->artisan('db:backup', ['--keep' => '0'])->assertFailed();
        $this->artisan('db:backup', ['--keep' => 'много'])->assertFailed();

        Process::assertNothingRan();
    }

    public function test_the_backup_is_scheduled_twice_a_month(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'db:backup'));

        $this->assertCount(1, $events, 'Задача db:backup должна быть ровно одна.');
        $this->assertSame('0 3 1,16 * *', $events->first()->expression);
    }
}
