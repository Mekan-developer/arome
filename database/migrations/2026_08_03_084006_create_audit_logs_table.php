<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('happened_at');
            $table->string('actor');
            $table->string('action');
            $table->string('object')->nullable();
            $table->string('value_from')->nullable();
            $table->string('value_to')->nullable();
            $table->enum('kind', ['auth', 'price', 'stock', 'import', 'rights', 'product', 'user', 'point']);
            $table->timestamps();

            $table->index('kind');
            $table->index('happened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
