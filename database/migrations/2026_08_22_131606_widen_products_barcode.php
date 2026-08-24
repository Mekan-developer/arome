<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Not every supplier code is an EAN-13: the panel now accepts a barcode of any length,
 * so the column has to hold more than thirteen characters. 64 is the width the import
 * already validates against, so both entry points agree on the same limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setBarcodeLength(64);
    }

    public function down(): void
    {
        $this->setBarcodeLength(13);
    }

    /**
     * SQLite ignores the declared varchar length, and rebuilding the table there would
     * only risk the unique index for a constraint the driver never enforced.
     */
    private function setBarcodeLength(int $length): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('products', function (Blueprint $table) use ($length): void {
            $table->string('barcode', $length)->change();
        });
    }
};
