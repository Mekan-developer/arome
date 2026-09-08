<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Не у каждого товара есть штрихкод: прайс поставщика приходит со свободной колонкой C,
 * и такая строка теперь сохраняется как есть — без придуманного кода. Пустая ячейка
 * ложится в базу как NULL, а не пустой строкой: уникальный индекс считает NULL-ы разными,
 * поэтому товаров без штрихкода может быть сколько угодно, а вот вторую пустую строку он
 * бы уже отбил.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable()->change();
        });

        DB::table('products')->where('barcode', '')->update(['barcode' => null]);
    }

    /**
     * Откат возможен, только пока без штрихкода не больше одного товара: пустая строка
     * ровно одна, а уникальный индекс второй такой не пропустит.
     */
    public function down(): void
    {
        DB::table('products')->whereNull('barcode')->update(['barcode' => '']);

        Schema::table('products', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable(false)->change();
        });
    }
};
