<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * main_code теряет тот же уникальный индекс, что раньше сняли с sku и barcode: под
 * ним остаётся обычный индекс — он всё ещё нужен поиску, импорту и nextMainCode(),
 * но второй такой же товар больше не отбивает. Дубли ImportService::apply() по-прежнему
 * переприсваивает свободным кодом сам, на уровне приложения, а не через отказ базы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['main_code']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('main_code', 64)->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index('main_code');
        });

        DB::table('products')->where('main_code', '')->update(['main_code' => null]);
    }

    /**
     * Откат возможен, только пока дублей и пустых основных кодов в каталоге нет:
     * обратно встаёт уникальный индекс, и второй такой же товар он уже не пропустит.
     */
    public function down(): void
    {
        DB::table('products')->whereNull('main_code')->update(['main_code' => '']);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['main_code']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('main_code', 64)->nullable(false)->change();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique('main_code');
        });
    }
};
