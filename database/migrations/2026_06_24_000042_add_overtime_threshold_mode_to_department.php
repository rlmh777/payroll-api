<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department', function (Blueprint $table) {
            if (!Schema::hasColumn('department', 'overtimeThresholdMode')) {
                $table->string('overtimeThresholdMode', 16)
                    ->default('DAILY')
                    ->after('totalWeeklyHoursBeforeOvertime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('department', function (Blueprint $table) {
            if (Schema::hasColumn('department', 'overtimeThresholdMode')) {
                $table->dropColumn('overtimeThresholdMode');
            }
        });
    }
};
