<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheet', 'includeLunchHour')) {
                $table->boolean('includeLunchHour')->nullable()->after('clockedHoursWorked');
            }

            if (!Schema::hasColumn('timesheet', 'lunchHourHours')) {
                $table->decimal('lunchHourHours', 4, 2)->nullable()->after('includeLunchHour');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            if (Schema::hasColumn('timesheet', 'lunchHourHours')) {
                $table->dropColumn('lunchHourHours');
            }

            if (Schema::hasColumn('timesheet', 'includeLunchHour')) {
                $table->dropColumn('includeLunchHour');
            }
        });
    }
};
