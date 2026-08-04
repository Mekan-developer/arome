<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Флаги модулей — не данные, а часть схемы: без строки в таблице раздел не
     * показать и не переключить в консоли /su. Поэтому они создаются миграцией,
     * а не сидером.
     *
     * @var list<array{key: string, is_enabled: bool, depends_on: string|null}>
     */
    private const MODULES = [
        ['key' => 'points', 'is_enabled' => false, 'depends_on' => null],
        ['key' => 'warehouses', 'is_enabled' => false, 'depends_on' => 'points'],
        ['key' => 'productPoints', 'is_enabled' => false, 'depends_on' => 'points'],
        ['key' => 'import', 'is_enabled' => true, 'depends_on' => null],
        ['key' => 'devices', 'is_enabled' => true, 'depends_on' => null],
        ['key' => 'audit', 'is_enabled' => true, 'depends_on' => null],
    ];

    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->string('depends_on', 32)->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('modules')->insert(array_map(
            fn (array $module): array => $module + ['created_at' => $now, 'updated_at' => $now],
            self::MODULES,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
