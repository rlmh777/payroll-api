<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pay_period_id');
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->timestamps();

            $table->foreign('pay_period_id')
                ->references('id')->on('pay_periods')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->index(['pay_period_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop dependent FKs if they exist before dropping the table (Postgres-safe)
        try { DB::statement('ALTER TABLE historical_employee_deduction DROP CONSTRAINT IF EXISTS historical_employee_deduction_payrollid_foreign'); } catch (\Throwable $e) {}
        try { DB::statement('ALTER TABLE historical_employee_deduction DROP CONSTRAINT IF EXISTS historical_employee_deduction_payroll_run_id_foreign'); } catch (\Throwable $e) {}
        try { DB::statement('ALTER TABLE historical_employee_allowance DROP CONSTRAINT IF EXISTS historical_employee_allowance_payrollid_foreign'); } catch (\Throwable $e) {}
        try { DB::statement('ALTER TABLE historical_employee_allowance DROP CONSTRAINT IF EXISTS historical_employee_allowance_payroll_run_id_foreign'); } catch (\Throwable $e) {}
        Schema::dropIfExists('payroll_runs');
    }
};

