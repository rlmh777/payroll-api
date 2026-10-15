<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_compensation', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_compensation', 'standardWeeklyHours')) {
                $table->decimal('standardWeeklyHours', 5, 2)
                    ->nullable()
                    ->after('yearlyRate');
            }
        });

        DB::table('employee_compensation')
            ->whereIn('compensationMethod', ['BASE_NO_OT', 'BASE_OT', 'SALARY_NO_CLOCK', 'BASE_SALARY', 'WEEKLY_SALARY', 'WEEKLY_SALARY_OT'])
            ->where(function ($query) {
                $query->whereNull('standardWeeklyHours')
                    ->orWhere('standardWeeklyHours', '<=', 0);
            })
            ->update(['standardWeeklyHours' => 40]);
    }

    public function down(): void
    {
        Schema::table('employee_compensation', function (Blueprint $table) {
            if (Schema::hasColumn('employee_compensation', 'standardWeeklyHours')) {
                $table->dropColumn('standardWeeklyHours');
            }
        });
    }
};
