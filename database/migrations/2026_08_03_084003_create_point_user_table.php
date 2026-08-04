<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('point_id')->constrained()->cascadeOnDelete();

            $table->unique(['user_id', 'point_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_user');
    }
};
