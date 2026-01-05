<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // historical_employee_deduction
        Schema::table('historical_employee_deduction', function (Blueprint $table) {
            // Drop old payrollId if it exists
            try {
                DB::statement('ALTER TABLE historical_employee_deduction DROP CONSTRAINT IF EXISTS historical_employee_deduction_payrollid_foreign');
            } catch (\Throwable $e) {
                // Constraint might not exist, continue
            }
            if (Schema::hasColumn('historical_employee_deduction', 'payrollId')) {
                $table->dropColumn('payrollId');
            }
            
            // Add payroll_run_id if it doesn't exist
            if (!Schema::hasColumn('historical_employee_deduction', 'payroll_run_id')) {
                $table->uuid('payroll_run_id');
                $table->foreign('payroll_run_id')
                    ->references('id')->on('payroll_runs')
                    ->onDelete('cascade');
            }
        });

        // historical_employee_allowance
        Schema::table('historical_employee_allowance', function (Blueprint $table) {
            try {
                DB::statement('ALTER TABLE historical_employee_allowance DROP CONSTRAINT IF EXISTS historical_employee_allowance_payrollid_foreign');
            } catch (\Throwable $e) {
                // Constraint might not exist, continue
            }
            if (Schema::hasColumn('historical_employee_allowance', 'payrollId')) {
                $table->dropColumn('payrollId');
            }
            
            // Add payroll_run_id if it doesn't exist
            if (!Schema::hasColumn('historical_employee_allowance', 'payroll_run_id')) {
                $table->uuid('payroll_run_id');
                $table->foreign('payroll_run_id')
                    ->references('id')->on('payroll_runs')
                    ->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        // Revert historical_employee_deduction
        Schema::table('historical_employee_deduction', function (Blueprint $table) {
            if (Schema::hasColumn('historical_employee_deduction', 'payroll_run_id')) {
                try {
                    $table->dropForeign(['payroll_run_id']);
                } catch (\Throwable $e) {
                    // Foreign key might not exist, continue
                }
                $table->dropColumn('payroll_run_id');
            }
            // Only add payrollId if it doesn't exist
            if (!Schema::hasColumn('historical_employee_deduction', 'payrollId')) {
                $table->foreignUuid('payrollId')->constrained('payroll_runs')->onDelete('cascade');
            }
        });

        // Revert historical_employee_allowance
        Schema::table('historical_employee_allowance', function (Blueprint $table) {
            if (Schema::hasColumn('historical_employee_allowance', 'payroll_run_id')) {
                try {
                    $table->dropForeign(['payroll_run_id']);
                } catch (\Throwable $e) {
                    // Foreign key might not exist, continue
                }
                $table->dropColumn('payroll_run_id');
            }
            // Only add payrollId if it doesn't exist
            if (!Schema::hasColumn('historical_employee_allowance', 'payrollId')) {
                $table->foreignUuid('payrollId')->constrained('payroll_runs')->onDelete('cascade');
            }
        });
    }
};

