<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_setting', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_setting', 'timesheetLockBeforeDate')) {
                $table->date('timesheetLockBeforeDate')->nullable()->after('secondReliefAmount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_setting', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_setting', 'timesheetLockBeforeDate')) {
                $table->dropColumn('timesheetLockBeforeDate');
            }
        });
    }
};
