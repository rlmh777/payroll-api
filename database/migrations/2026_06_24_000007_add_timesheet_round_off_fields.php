<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheet', 'roundOffClockInTime')) {
                $table->dateTime('roundOffClockInTime')->nullable()->after('clockOutDeviceId');
            }

            if (!Schema::hasColumn('timesheet', 'roundOffClockOutTime')) {
                $table->dateTime('roundOffClockOutTime')->nullable()->after('roundOffClockInTime');
            }

            if (!Schema::hasColumn('timesheet', 'clockedHoursWorked')) {
                $table->decimal('clockedHoursWorked', 8, 2)->default(0)->after('roundOffClockOutTime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            $columns = ['roundOffClockInTime', 'roundOffClockOutTime', 'clockedHoursWorked'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('timesheet', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
