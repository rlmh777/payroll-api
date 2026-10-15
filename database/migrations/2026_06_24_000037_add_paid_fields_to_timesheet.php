<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheet', 'isPaid')) {
                $table->boolean('isPaid')->default(true)->after('unpaidHours');
            }
            if (!Schema::hasColumn('timesheet', 'paidHours')) {
                $table->decimal('paidHours', 8, 2)->default(0)->after('isPaid');
            }
        });

        DB::statement('
            UPDATE timesheet
            SET "paidHours" = ROUND(
                COALESCE("regularHours", 0) + COALESCE("overtimeHours", 0) + COALESCE("holidayHours", 0),
                2
            )
            WHERE COALESCE("paidHours", 0) = 0
        ');

        DB::statement('
            UPDATE timesheet
            SET "isPaid" = false, "paidHours" = 0
            WHERE UPPER("workingStatus") = \'UNPAID\'
              AND COALESCE("hoursWorked", 0) = 0
              AND COALESCE("unpaidHours", 0) > 0
        ');
    }

    public function down(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            if (Schema::hasColumn('timesheet', 'paidHours')) {
                $table->dropColumn('paidHours');
            }
            if (Schema::hasColumn('timesheet', 'isPaid')) {
                $table->dropColumn('isPaid');
            }
        });
    }
};
