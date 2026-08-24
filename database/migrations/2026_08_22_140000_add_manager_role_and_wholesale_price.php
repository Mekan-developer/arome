<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Менеджер — третья роль: то же мобильное приложение, что у продавца, но с оптовой
 * ценой товара. Отсюда три изменения схемы: колонка оптовой цены в каталоге, роль
 * «manager» у сотрудников и в матрице прав, поле «wholesale» в самой матрице.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'wholesale_price')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->decimal('wholesale_price', 10, 2)->nullable();
            });
        }

        $this->rewriteConstraint('users', 'role', ['admin', 'manager', 'seller', 'superadmin']);
        $this->rewriteConstraint('role_field_rights', 'role', ['admin', 'manager', 'seller']);
        $this->rewriteConstraint('role_field_rights', 'field', ['mainCode', 'name', 'sku', 'barcode', 'stock', 'retail', 'wholesale', 'discount']);
    }

    public function down(): void
    {
        DB::table('role_field_rights')->where('role', 'manager')->orWhere('field', 'wholesale')->delete();
        DB::table('users')->where('role', 'manager')->update(['role' => 'seller']);

        $this->rewriteConstraint('users', 'role', ['admin', 'seller', 'superadmin']);
        $this->rewriteConstraint('role_field_rights', 'role', ['admin', 'seller']);
        $this->rewriteConstraint('role_field_rights', 'field', ['mainCode', 'name', 'sku', 'barcode', 'stock', 'retail', 'discount']);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('wholesale_price');
        });
    }

    /**
     * На Postgres «enum» — это varchar с check-констрейнтом, и список значений меняется
     * только заменой самого констрейнта: `enum()->change()` собрал бы недопустимый для
     * Postgres `alter column … type varchar check (…)`.
     *
     * Остальные драйверы не трогаем: SQLite вшивает check в DDL таблицы, поэтому база,
     * созданная с нуля, уже несёт расширенный список — так же, как в миграции
     * {@see 2026_08_04_085407_drop_archived_product_status.php}.
     *
     * @param  list<string>  $values
     */
    private function rewriteConstraint(string $table, string $column, array $values): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = implode(', ', array_map(fn (string $value): string => "'".$value."'", $values));
        $name = $table.'_'.$column.'_check';

        DB::statement('alter table '.$table.' drop constraint if exists '.$name);
        DB::statement('alter table '.$table.' add constraint '.$name.' check ('.$column.' in ('.$allowed.'))');
    }
};
