<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // historical_employee_deduction: drop payrollId -> add payroll_run_id
        Schema::table('historical_employee_deduction', function (Blueprint $table) {
            // Drop FK if present, then column (Postgres-safe)
            try { DB::statement('ALTER TABLE historical_employee_deduction DROP CONSTRAINT IF EXISTS historical_employee_deduction_payrollid_foreign'); } catch (\Throwable $e) {}
            if (Schema::hasColumn('historical_employee_deduction', 'payrollId')) {
                $table->dropColumn('payrollId');
            }

            if (!Schema::hasColumn('historical_employee_deduction', 'payroll_run_id')) {
                $table->uuid('payroll_run_id');
                $table->foreign('payroll_run_id')
                    ->references('id')->on('payroll_runs')
                    ->onDelete('cascade');
            }
        });

        // historical_employee_allowance: drop payrollId -> add payroll_run_id
        Schema::table('historical_employee_allowance', function (Blueprint $table) {
            try { DB::statement('ALTER TABLE historical_employee_allowance DROP CONSTRAINT IF EXISTS historical_employee_allowance_payrollid_foreign'); } catch (\Throwable $e) {}
            if (Schema::hasColumn('historical_employee_allowance', 'payrollId')) {
                $table->dropColumn('payrollId');
            }

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
                $table->dropForeign(['payroll_run_id']);
                $table->dropColumn('payroll_run_id');
            }
            if (!Schema::hasColumn('historical_employee_deduction', 'payrollId')) {
                $table->foreignUuid('payrollId')->constrained('payroll')->onDelete('cascade');
            }
        });

        // Revert historical_employee_allowance
        Schema::table('historical_employee_allowance', function (Blueprint $table) {
            if (Schema::hasColumn('historical_employee_allowance', 'payroll_run_id')) {
                $table->dropForeign(['payroll_run_id']);
                $table->dropColumn('payroll_run_id');
            }
            if (!Schema::hasColumn('historical_employee_allowance', 'payrollId')) {
                $table->foreignUuid('payrollId')->constrained('payroll')->onDelete('cascade');
            }
        });
    }
};


