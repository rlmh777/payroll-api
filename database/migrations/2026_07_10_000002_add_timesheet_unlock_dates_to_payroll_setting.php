<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_setting')) {
            Schema::table('payroll_setting', function (Blueprint $table) {
                if (!Schema::hasColumn('payroll_setting', 'timesheetUnlockStartDate')) {
                    $table->date('timesheetUnlockStartDate')->nullable()->after('secondReliefAmount');
                }

                if (!Schema::hasColumn('payroll_setting', 'timesheetUnlockEndDate')) {
                    $table->date('timesheetUnlockEndDate')->nullable()->after('timesheetUnlockStartDate');
                }
            });
        }

        if (Schema::hasTable('timesheet')) {
            Schema::table('timesheet', function (Blueprint $table) {
                if (Schema::hasColumn('timesheet', 'editUnlockedBy')) {
                    $table->dropForeign(['editUnlockedBy']);
                    $table->dropColumn('editUnlockedBy');
                }

                if (Schema::hasColumn('timesheet', 'editUnlockedAt')) {
                    $table->dropColumn('editUnlockedAt');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_setting')) {
            Schema::table('payroll_setting', function (Blueprint $table) {
                if (Schema::hasColumn('payroll_setting', 'timesheetUnlockEndDate')) {
                    $table->dropColumn('timesheetUnlockEndDate');
                }

                if (Schema::hasColumn('payroll_setting', 'timesheetUnlockStartDate')) {
                    $table->dropColumn('timesheetUnlockStartDate');
                }
            });
        }

        if (Schema::hasTable('timesheet')) {
            Schema::table('timesheet', function (Blueprint $table) {
                if (!Schema::hasColumn('timesheet', 'editUnlockedAt')) {
                    $table->timestamp('editUnlockedAt')->nullable()->after('remarks');
                }

                if (!Schema::hasColumn('timesheet', 'editUnlockedBy')) {
                    $table->foreignUuid('editUnlockedBy')
                        ->nullable()
                        ->after('editUnlockedAt')
                        ->constrained('employee')
                        ->nullOnDelete();
                }
            });
        }
    }
};
