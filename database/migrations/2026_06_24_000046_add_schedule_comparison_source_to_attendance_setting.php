<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_setting', function (Blueprint $table) {
            $table->string('scheduleComparisonSource', 20)
                ->default('ROUNDED')
                ->after('clockRoundOffMinutes');
        });

        DB::table('attendance_setting')->update([
            'scheduleComparisonSource' => 'ROUNDED',
        ]);
    }

    public function down(): void
    {
        Schema::table('attendance_setting', function (Blueprint $table) {
            $table->dropColumn('scheduleComparisonSource');
        });
    }
};
