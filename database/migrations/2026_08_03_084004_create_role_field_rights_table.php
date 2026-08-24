<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_field_rights', function (Blueprint $table) {
            $table->id();
            $table->enum('role', ['admin', 'manager', 'seller']);
            $table->enum('field', ['mainCode', 'name', 'sku', 'barcode', 'stock', 'retail', 'wholesale', 'discount']);
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->unique(['role', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_field_rights');
    }
};
