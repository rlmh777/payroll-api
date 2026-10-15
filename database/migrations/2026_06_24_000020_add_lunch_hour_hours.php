<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department', function (Blueprint $table) {
            if (!Schema::hasColumn('department', 'lunchHourHours')) {
                $table->decimal('lunchHourHours', 4, 2)->default(1)->after('includeLunchHour');
            }
        });

        if (Schema::hasTable('scheduled_work') && !Schema::hasColumn('scheduled_work', 'lunchHourHours')) {
            Schema::table('scheduled_work', function (Blueprint $table) {
                $table->decimal('lunchHourHours', 4, 2)->default(1)->after('includeLunchHour');
            });
        }

        if (Schema::hasTable('schedule_employee_timesheet') && !Schema::hasColumn('schedule_employee_timesheet', 'lunch_hour_hours')) {
            Schema::table('schedule_employee_timesheet', function (Blueprint $table) {
                $table->decimal('lunch_hour_hours', 4, 2)->default(1)->after('include_lunch_hour');
            });
        }
    }

    public function down(): void
    {
        Schema::table('department', function (Blueprint $table) {
            if (Schema::hasColumn('department', 'lunchHourHours')) {
                $table->dropColumn('lunchHourHours');
            }
        });

        if (Schema::hasTable('scheduled_work') && Schema::hasColumn('scheduled_work', 'lunchHourHours')) {
            Schema::table('scheduled_work', function (Blueprint $table) {
                $table->dropColumn('lunchHourHours');
            });
        }

        if (Schema::hasTable('schedule_employee_timesheet') && Schema::hasColumn('schedule_employee_timesheet', 'lunch_hour_hours')) {
            Schema::table('schedule_employee_timesheet', function (Blueprint $table) {
                $table->dropColumn('lunch_hour_hours');
            });
        }
    }
};
