<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Прайс поставщика приходит как есть: артикул бывает пустым, а одна и та же позиция
 * встречается в файле дважды. Заказчик выбрал сохранять такие строки обеими карточками,
 * поэтому артикул и штрихкод перестают быть уникальными — под ними остаются обычные
 * индексы, они нужны поиску и сканеру, но второй такой же товар больше не отбивают.
 *
 * Основной код уникальным остаётся: это код самой панели, а не поставщика, и дублю он
 * присваивается заново — см. ImportService::apply().
 *
 * Заодно артикул и основной код расширены до 64 символов: ровно столько пропускает
 * ConfirmImportRequest, и до сих пор строка длиннее падала бы уже на вставке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['sku']);
            $table->dropUnique(['barcode']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('sku', 64)->nullable()->change();
            $table->string('main_code', 64)->change();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index('sku');
            $table->index('barcode');
        });

        DB::table('products')->where('sku', '')->update(['sku' => null]);
    }

    /**
     * Откат возможен, только пока дублей и пустых артикулов в каталоге нет: обратно
     * встаёт уникальный индекс, и второй такой же товар он уже не пропустит.
     */
    public function down(): void
    {
        DB::table('products')->whereNull('sku')->update(['sku' => '']);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['sku']);
            $table->dropIndex(['barcode']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('sku', 32)->nullable(false)->change();
            $table->string('main_code', 16)->change();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique('sku');
            $table->unique('barcode');
        });
    }
};
