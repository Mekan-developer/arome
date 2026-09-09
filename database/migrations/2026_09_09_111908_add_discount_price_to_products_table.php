<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цена со скидкой, названная прайсом напрямую. В файле поставщика колонка «Скидки»
 * бывает пустой, а «Цена со скидкой» — заполненной: процента, из которого её можно
 * было бы вычислить, там просто нет, и до сих пор такая строка теряла скидку целиком.
 *
 * NULL — это «своей цены со скидкой у товара нет», и продажная цена считается как
 * раньше, розничная минус процент. Ноль так не прочитать: ноль — это цена.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('discount_price', 10, 2)->nullable()->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('discount_price');
        });
    }
};
