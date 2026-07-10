<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('department')) {
            return;
        }

        Schema::table('department', function (Blueprint $table) {
            if (!Schema::hasColumn('department', 'overnightShiftMode')) {
                $table->string('overnightShiftMode', 32)
                    ->default('SPLIT_AT_MIDNIGHT')
                    ->after('overtimeThresholdMode');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('department')) {
            return;
        }

        Schema::table('department', function (Blueprint $table) {
            if (Schema::hasColumn('department', 'overnightShiftMode')) {
                $table->dropColumn('overnightShiftMode');
            }
        });
    }
};
