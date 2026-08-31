<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_runs')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('payroll_runs', 'timesheet_lock_applied_at')) {
                    $table->timestamp('timesheet_lock_applied_at')->nullable()->after('status');
                }
            });
        }

        if (Schema::hasTable('payroll_setting')) {
            Schema::table('payroll_setting', function (Blueprint $table) {
                if (! Schema::hasColumn('payroll_setting', 'timesheetAutoLockEnabled')) {
                    $table->boolean('timesheetAutoLockEnabled')->default(true)->after('timesheetLockBeforeDate');
                }

                if (! Schema::hasColumn('payroll_setting', 'timesheetAutoLockTime')) {
                    $table->string('timesheetAutoLockTime', 5)->default('17:00')->after('timesheetAutoLockEnabled');
                }

                if (! Schema::hasColumn('payroll_setting', 'timesheetAutoLockDaysAfterPayDate')) {
                    $table->unsignedTinyInteger('timesheetAutoLockDaysAfterPayDate')->default(1)->after('timesheetAutoLockTime');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_runs')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                if (Schema::hasColumn('payroll_runs', 'timesheet_lock_applied_at')) {
                    $table->dropColumn('timesheet_lock_applied_at');
                }
            });
        }

        if (Schema::hasTable('payroll_setting')) {
            Schema::table('payroll_setting', function (Blueprint $table) {
                foreach ([
                    'timesheetAutoLockDaysAfterPayDate',
                    'timesheetAutoLockTime',
                    'timesheetAutoLockEnabled',
                ] as $column) {
                    if (Schema::hasColumn('payroll_setting', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
