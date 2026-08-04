<?php

use App\Enums\ProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Архив» is gone from the panel. Rows already sitting in that status become «скрыт» —
 * the closest thing left: the card stays in the base and in the reports, but the till
 * and the app do not see it. Without this the cast to ProductStatus throws on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->moveArchivedToHidden();
        $this->rewriteStatusConstraint(['active', 'hidden']);
    }

    public function down(): void
    {
        $this->rewriteStatusConstraint(['active', 'hidden', 'archived']);
    }

    private function moveArchivedToHidden(): void
    {
        DB::table('products')
            ->where('status', 'archived')
            ->update(['status' => ProductStatus::Hidden->value]);
    }

    /**
     * On Postgres an "enum" column is a varchar with a check constraint, and the allowed
     * values can only be changed by replacing that constraint — `enum()->change()` builds
     * an `alter column … type varchar check (…)`, which is not valid Postgres.
     *
     * Every other driver is left alone: SQLite bakes the check into the table DDL, so a
     * database created from scratch already carries the narrowed list, and rebuilding the
     * table for a constraint the application no longer relies on is not worth it.
     *
     * @param  list<string>  $statuses
     */
    private function rewriteStatusConstraint(array $statuses): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = implode(', ', array_map(fn (string $status): string => "'".$status."'", $statuses));

        DB::statement('alter table products drop constraint if exists products_status_check');
        DB::statement('alter table products add constraint products_status_check check (status in ('.$allowed.'))');
    }
};
