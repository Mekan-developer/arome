<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('point_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model');
            $table->string('app_version', 16);
            $table->timestamp('synced_at');
            $table->unsignedInteger('data_version');
            $table->unsignedInteger('lag')->default(0);
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
