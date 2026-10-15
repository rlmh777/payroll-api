<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            if (!Schema::hasColumn('department', 'excludeLunch')) {
                $table->boolean('excludeLunch')->default(false)->after('totalWeeklyHoursBeforeOvertime');
            }
        });

        DB::table('department')
            ->whereNull('totalDailyHoursBeforeOvertime')
            ->update(['totalDailyHoursBeforeOvertime' => 9]);

        DB::table('department')
            ->whereNull('totalWeeklyHoursBeforeOvertime')
            ->update(['totalWeeklyHoursBeforeOvertime' => 45]);

        DB::statement('ALTER TABLE department ALTER COLUMN "totalDailyHoursBeforeOvertime" SET DEFAULT 9');
        DB::statement('ALTER TABLE department ALTER COLUMN "totalWeeklyHoursBeforeOvertime" SET DEFAULT 45');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('department')) {
            return;
        }

        DB::statement('ALTER TABLE department ALTER COLUMN "totalDailyHoursBeforeOvertime" DROP DEFAULT');
        DB::statement('ALTER TABLE department ALTER COLUMN "totalWeeklyHoursBeforeOvertime" DROP DEFAULT');

        if (Schema::hasColumn('department', 'excludeLunch')) {
            Schema::table('department', function (Blueprint $table) {
                $table->dropColumn('excludeLunch');
            });
        }
    }
};
