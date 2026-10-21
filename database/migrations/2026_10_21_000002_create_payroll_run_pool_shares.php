<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_pool_share', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payroll_run_id');
            $table->unsignedBigInteger('pool_distribution_type_id');
            $table->uuid('employee_id');
            $table->decimal('shares', 14, 4);
            $table->timestamps();

            $table->unique(
                ['payroll_run_id', 'pool_distribution_type_id', 'employee_id'],
                'payroll_run_pool_share_unique',
            );
            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
            $table->foreign('pool_distribution_type_id')
                ->references('id')
                ->on('pool_distribution_type')
                ->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employee')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_pool_share');
    }
};
