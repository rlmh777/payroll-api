<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('timesheet', 'slotIndex')) {
            Schema::table('timesheet', function (Blueprint $table) {
                $table->unsignedSmallInteger('slotIndex')->default(0)->after('date');
            });
        }

        Schema::table('timesheet', function (Blueprint $table) {
            $table->unique(['employeeId', 'date', 'slotIndex'], 'timesheet_employee_date_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            $table->dropUnique('timesheet_employee_date_slot_unique');
        });

        if (Schema::hasColumn('timesheet', 'slotIndex')) {
            Schema::table('timesheet', function (Blueprint $table) {
                $table->dropColumn('slotIndex');
            });
        }
    }
};
