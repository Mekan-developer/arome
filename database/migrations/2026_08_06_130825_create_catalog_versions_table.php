<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ревизия каталога, опубликованная на телефоны. Как и флаги модулей, стартовая
     * строка — часть схемы, а не данные: без неё синхронизация не с чем сравнивать
     * отставание устройства, поэтому она создаётся миграцией, а не сидером.
     *
     * Пустой каталог — это ревизия 0. Дальше номер растёт на каждой правке, и первый
     * же залитый прайс делает его первым.
     */
    public function up(): void
    {
        Schema::create('catalog_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('catalog_versions')->insert([
            'number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_versions');
    }
};
