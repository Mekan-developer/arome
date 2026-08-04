<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The product search runs `ILIKE '%…%'` over the name, which cannot use a btree
     * index and degrades into a full scan once the price list grows. A trigram GIN
     * index makes that predicate indexable on PostgreSQL.
     */
    public function up(): void
    {
        if (! $this->onPostgres()) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS products_name_trgm_idx ON products USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS products_status_name_idx ON products (status, name)');
    }

    public function down(): void
    {
        if (! $this->onPostgres()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS products_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS products_status_name_idx');
    }

    private function onPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
