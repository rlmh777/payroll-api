<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_earning_code')
            || Schema::hasColumn('payroll_earning_code', 'split_by_timesheet_department')) {
            return;
        }

        Schema::table('payroll_earning_code', function (Blueprint $table) {
            $table->boolean('split_by_timesheet_department')
                ->default(false)
                ->after('post_to_department_account');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_earning_code')
            || ! Schema::hasColumn('payroll_earning_code', 'split_by_timesheet_department')) {
            return;
        }

        Schema::table('payroll_earning_code', function (Blueprint $table) {
            $table->dropColumn('split_by_timesheet_department');
        });
    }
};
