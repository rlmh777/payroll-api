<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('department')) {
            return;
        }

        Schema::table('department', function (Blueprint $table) {
            if (!Schema::hasColumn('department', 'totalDailyHoursBeforeOvertime')) {
                $table->decimal('totalDailyHoursBeforeOvertime', 5, 2)->nullable()->after('parentId');
            }

            if (!Schema::hasColumn('department', 'totalWeeklyHoursBeforeOvertime')) {
                $table->decimal('totalWeeklyHoursBeforeOvertime', 6, 2)->nullable()->after('totalDailyHoursBeforeOvertime');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('department')) {
            return;
        }

        Schema::table('department', function (Blueprint $table) {
            if (Schema::hasColumn('department', 'totalWeeklyHoursBeforeOvertime')) {
                $table->dropColumn('totalWeeklyHoursBeforeOvertime');
            }

            if (Schema::hasColumn('department', 'totalDailyHoursBeforeOvertime')) {
                $table->dropColumn('totalDailyHoursBeforeOvertime');
            }
        });
    }
};
