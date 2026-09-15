<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Торговый представитель — роль, появившаяся позже: в схеме только расширяем
 * допустимые значения role у сотрудников и в матрице прав.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->expandRole(
            'users',
            ['admin', 'manager', 'representative', 'seller', 'superadmin'],
            'seller',
        );
        $this->expandRole(
            'role_field_rights',
            ['admin', 'manager', 'representative', 'seller'],
        );
    }

    public function down(): void
    {
        DB::table('role_field_rights')->where('role', 'representative')->delete();
        DB::table('users')->where('role', 'representative')->update(['role' => 'seller']);

        $this->expandRole(
            'users',
            ['admin', 'manager', 'seller', 'superadmin'],
            'seller',
        );
        $this->expandRole(
            'role_field_rights',
            ['admin', 'manager', 'seller'],
        );
    }

    /**
     * @param  list<string>  $values
     */
    private function expandRole(string $table, array $values, ?string $default = null): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $allowed = implode(', ', array_map(fn (string $value): string => "'".$value."'", $values));
            $name = $table.'_role_check';

            DB::statement('alter table '.$table.' drop constraint if exists '.$name);
            DB::statement('alter table '.$table.' add constraint '.$name.' check (role in ('.$allowed.'))');

            return;
        }

        /*
         * SQLite держит check в DDL таблицы: без пересборки колонки новая роль
         * в свежей тестовой базе не пройдёт. Старые create-миграции не трогаем.
         */
        if ($driver === 'sqlite') {
            Schema::table($table, function (Blueprint $blueprint) use ($values, $default): void {
                $column = $blueprint->enum('role', $values);
                if ($default !== null) {
                    $column->default($default);
                }
                $column->change();
            });
        }
    }
};
