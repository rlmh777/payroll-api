<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('timesheet', 'clockInPunctualityManual')) {
            return;
        }

        DB::table('timesheet')
            ->where('clockInPunctualityManual', false)
            ->update(['clockInPunctuality' => null]);

        DB::table('timesheet')
            ->where('clockOutPunctualityManual', false)
            ->update(['clockOutPunctuality' => null]);

        Schema::table('timesheet', function (Blueprint $table) {
            $table->dropColumn([
                'clockInPunctualityManual',
                'clockOutPunctualityManual',
            ]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('timesheet', 'clockInPunctualityManual')) {
            return;
        }

        Schema::table('timesheet', function (Blueprint $table) {
            $table->boolean('clockInPunctualityManual')->default(false)->after('clockOutPunctuality');
            $table->boolean('clockOutPunctualityManual')->default(false)->after('clockInPunctualityManual');
        });

        DB::table('timesheet')
            ->whereNotNull('clockInPunctuality')
            ->update(['clockInPunctualityManual' => true]);

        DB::table('timesheet')
            ->whereNotNull('clockOutPunctuality')
            ->update(['clockOutPunctualityManual' => true]);
    }
};
