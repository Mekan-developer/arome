<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * История сканирований продавца. Строка на пару «сотрудник + товар», а не на
     * каждое поднесение сканера: повторный скан поднимает товар наверх и увеличивает
     * счётчик. Поэтому история — это «последние N разных товаров», а таблица сверху
     * ограничена размером каталога и не требует чистки по расписанию.
     */
    public function up(): void
    {
        Schema::create('product_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Имя токена Sanctum — модель телефона, с которого пришёл скан.
            $table->string('device')->nullable();
            $table->unsignedInteger('times')->default(1);
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
            $table->index(['user_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_scans');
    }
};
