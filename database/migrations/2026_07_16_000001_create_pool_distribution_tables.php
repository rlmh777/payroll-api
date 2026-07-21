<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pool_distribution_type', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('calculation_mode', 32)->default('weighted_points');
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_hours_eligibility')->default(true);
            $table->unsignedBigInteger('payroll_earning_code_id')->nullable();
            $table->uuid('allowance_id')->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_ss_subject')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('payroll_earning_code_id')
                ->references('id')
                ->on('payroll_earning_code')
                ->nullOnDelete();

            if (Schema::hasTable('allowance')) {
                $table->foreign('allowance_id')
                    ->references('id')
                    ->on('allowance')
                    ->nullOnDelete();
            }
        });

        Schema::create('employee_pool_point', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->unsignedBigInteger('pool_distribution_type_id');
            $table->decimal('points', 12, 4)->default(0);
            $table->decimal('weight', 12, 4)->default(1);
            $table->date('effective_date');
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employee')->cascadeOnDelete();
            $table->foreign('pool_distribution_type_id')
                ->references('id')
                ->on('pool_distribution_type')
                ->cascadeOnDelete();
            $table->index(['employee_id', 'pool_distribution_type_id', 'effective_date'], 'employee_pool_point_lookup_idx');
        });

        Schema::create('employee_hours_bank', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_id')->unique();
            $table->decimal('balance_hours', 12, 4)->default(0);
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employee')->cascadeOnDelete();
        });

        Schema::create('employee_hours_bank_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            $table->date('entry_date');
            $table->date('week_start_date')->nullable();
            $table->uuid('payroll_run_id')->nullable();
            $table->uuid('employee_leave_id')->nullable();
            $table->string('entry_type', 32);
            $table->decimal('hours_delta', 12, 4);
            $table->decimal('balance_after', 12, 4);
            $table->decimal('expected_hours', 12, 4)->nullable();
            $table->decimal('worked_hours', 12, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employee')->cascadeOnDelete();
            $table->index(['employee_id', 'entry_date'], 'employee_hours_bank_ledger_lookup_idx');
            $table->index(['payroll_run_id', 'entry_type'], 'employee_hours_bank_ledger_run_idx');
        });

        Schema::create('payroll_run_pool_total', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payroll_run_id');
            $table->unsignedBigInteger('pool_distribution_type_id');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'pool_distribution_type_id'], 'payroll_run_pool_total_unique');
            $table->foreign('pool_distribution_type_id')
                ->references('id')
                ->on('pool_distribution_type')
                ->cascadeOnDelete();
        });

        Schema::create('payroll_run_pool_distribution', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payroll_run_id');
            $table->unsignedBigInteger('pool_distribution_type_id');
            $table->uuid('employee_id');
            $table->decimal('points', 12, 4)->default(0);
            $table->decimal('weight', 12, 4)->default(1);
            $table->decimal('weighted_points', 14, 4)->default(0);
            $table->decimal('share_ratio', 14, 8)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('is_eligible')->default(true);
            $table->string('eligibility_reason')->nullable();
            $table->decimal('worked_hours', 12, 4)->nullable();
            $table->decimal('expected_hours', 12, 4)->nullable();
            $table->decimal('bank_hours_applied', 12, 4)->nullable();
            $table->timestamps();

            $table->unique(
                ['payroll_run_id', 'pool_distribution_type_id', 'employee_id'],
                'payroll_run_pool_distribution_unique'
            );
            $table->foreign('pool_distribution_type_id')
                ->references('id')
                ->on('pool_distribution_type')
                ->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employee')->cascadeOnDelete();
            $table->index(['payroll_run_id', 'employee_id'], 'payroll_run_pool_distribution_run_emp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_pool_distribution');
        Schema::dropIfExists('payroll_run_pool_total');
        Schema::dropIfExists('employee_hours_bank_ledger');
        Schema::dropIfExists('employee_hours_bank');
        Schema::dropIfExists('employee_pool_point');
        Schema::dropIfExists('pool_distribution_type');
    }
};
