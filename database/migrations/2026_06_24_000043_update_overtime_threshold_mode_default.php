<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('department', 'overtimeThresholdMode')) {
            return;
        }

        DB::table('department')
            ->where('overtimeThresholdMode', 'DAILY')
            ->update(['overtimeThresholdMode' => 'DAILY_AND_WEEKLY']);

        DB::statement("ALTER TABLE department ALTER COLUMN \"overtimeThresholdMode\" SET DEFAULT 'DAILY_AND_WEEKLY'");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('department', 'overtimeThresholdMode')) {
            return;
        }

        DB::statement("ALTER TABLE department ALTER COLUMN \"overtimeThresholdMode\" SET DEFAULT 'DAILY'");
    }
};
