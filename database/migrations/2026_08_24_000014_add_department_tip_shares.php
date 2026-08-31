<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('pool_distribution_type')
            && Schema::hasTable('department')
            && ! Schema::hasTable('pool_distribution_type_department_share')
        ) {
            Schema::create('pool_distribution_type_department_share', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pool_distribution_type_id');
                $table->unsignedBigInteger('department_id');
                $table->decimal('percent', 6, 2);
                $table->timestamps();

                $table->unique(
                    ['pool_distribution_type_id', 'department_id'],
                    'pool_type_department_share_unique'
                );
                $table->foreign('pool_distribution_type_id', 'pool_type_dept_share_type_fk')
                    ->references('id')
                    ->on('pool_distribution_type')
                    ->cascadeOnDelete();
                $table->foreign('department_id', 'pool_type_dept_share_dept_fk')
                    ->references('id')
                    ->on('department')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('payroll_run_pool_distribution')) {
            Schema::table('payroll_run_pool_distribution', function (Blueprint $table) {
                if (! Schema::hasColumn('payroll_run_pool_distribution', 'department_id')) {
                    $table->unsignedBigInteger('department_id')->nullable()->after('employee_id');
                }
                if (! Schema::hasColumn('payroll_run_pool_distribution', 'department_percent')) {
                    $table->decimal('department_percent', 6, 2)->nullable()->after('department_id');
                }
                if (! Schema::hasColumn('payroll_run_pool_distribution', 'department_amount')) {
                    $table->decimal('department_amount', 14, 2)->nullable()->after('department_percent');
                }
                if (! Schema::hasColumn('payroll_run_pool_distribution', 'worked_this_period')) {
                    $table->boolean('worked_this_period')->nullable()->after('department_amount');
                }
            });
        }

        if (Schema::hasTable('pool_distribution_type')) {
            DB::table('pool_distribution_type')
                ->whereRaw('UPPER(code) = ?', ['TIPS'])
                ->update([
                    'calculation_mode' => 'department_equal_share',
                    'requires_hours_eligibility' => false,
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_run_pool_distribution')) {
            Schema::table('payroll_run_pool_distribution', function (Blueprint $table) {
                foreach (['worked_this_period', 'department_amount', 'department_percent', 'department_id'] as $column) {
                    if (Schema::hasColumn('payroll_run_pool_distribution', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('pool_distribution_type_department_share');
    }
};
