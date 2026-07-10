<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_employee_timesheet', function (Blueprint $table) {
            if (!Schema::hasColumn('schedule_employee_timesheet', 'departmentId')) {
                $table->unsignedBigInteger('departmentId')->nullable()->after('employeeId');
                $table->foreign('departmentId')->references('id')->on('department')->nullOnDelete();
            }

            if (!Schema::hasColumn('schedule_employee_timesheet', 'series_id')) {
                $table->uuid('series_id')->nullable()->after('departmentId');
                $table->index('series_id');
            }

            if (!Schema::hasColumn('schedule_employee_timesheet', 'include_lunch_hour')) {
                $table->boolean('include_lunch_hour')->default(false)->after('endTime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schedule_employee_timesheet', function (Blueprint $table) {
            if (Schema::hasColumn('schedule_employee_timesheet', 'departmentId')) {
                $table->dropForeign(['departmentId']);
                $table->dropColumn('departmentId');
            }

            if (Schema::hasColumn('schedule_employee_timesheet', 'series_id')) {
                $table->dropIndex(['series_id']);
                $table->dropColumn('series_id');
            }

            if (Schema::hasColumn('schedule_employee_timesheet', 'include_lunch_hour')) {
                $table->dropColumn('include_lunch_hour');
            }
        });
    }
};
