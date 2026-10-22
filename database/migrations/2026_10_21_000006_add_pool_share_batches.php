<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_pool_share_batch', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payroll_run_id');
            $table->unsignedBigInteger('pool_distribution_type_id');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('source_name')->nullable();
            $table->timestamps();

            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
            $table->foreign('pool_distribution_type_id')
                ->references('id')
                ->on('pool_distribution_type')
                ->cascadeOnDelete();
        });

        Schema::table('payroll_run_pool_share', function (Blueprint $table) {
            $table->dropUnique('payroll_run_pool_share_unique');
            $table->uuid('batch_id')->nullable();
            $table->foreign('batch_id')
                ->references('id')
                ->on('payroll_run_pool_share_batch')
                ->cascadeOnDelete();
            $table->unique(
                ['payroll_run_id', 'pool_distribution_type_id', 'employee_id', 'batch_id'],
                'payroll_run_pool_share_batch_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_pool_share', function (Blueprint $table) {
            $table->dropUnique('payroll_run_pool_share_batch_unique');
            $table->dropForeign(['batch_id']);
            $table->dropColumn('batch_id');
            $table->unique(
                ['payroll_run_id', 'pool_distribution_type_id', 'employee_id'],
                'payroll_run_pool_share_unique',
            );
        });

        Schema::dropIfExists('payroll_run_pool_share_batch');
    }
};
