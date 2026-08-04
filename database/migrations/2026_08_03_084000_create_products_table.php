<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('main_code', 16)->unique();
            $table->string('sku', 32)->unique();
            $table->string('barcode', 13)->unique();
            $table->string('name');
            $table->enum('kind', ['PARFUM', 'EDP', 'EDT', 'CARE']);
            $table->decimal('price', 10, 2);
            $table->decimal('discount', 5, 4)->default(0);
            $table->enum('status', ['active', 'hidden'])->default('active');
            $table->timestamps();

            $table->index('status');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
