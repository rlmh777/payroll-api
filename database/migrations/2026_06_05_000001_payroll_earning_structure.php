<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('deduction_type') && !Schema::hasColumn('deduction_type', 'category')) {
            Schema::table('deduction_type', function (Blueprint $table) {
                $table->string('category', 32)->default('VOLUNTARY')->after('name');
            });
        }

        Schema::create('payroll_earning_code', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 128);
            $table->foreignUuid('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_ss_subject')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        if (Schema::hasTable('payroll')) {
            Schema::table('payroll', function (Blueprint $table) {
                if (!Schema::hasColumn('payroll', 'payroll_run_id')) {
                    $table->uuid('payroll_run_id')->nullable()->after('employeeId');
                    $table->foreign('payroll_run_id')
                        ->references('id')->on('payroll_runs')
                        ->nullOnDelete();
                }
                if (!Schema::hasColumn('payroll', 'departmentId')) {
                    $table->foreignId('departmentId')->nullable()->after('payroll_run_id')->constrained('department')->nullOnDelete();
                }
                if (!Schema::hasColumn('payroll', 'holidayHours')) {
                    $table->decimal('holidayHours', 8, 2)->default(0)->after('totalOvertimeHours');
                }
                if (!Schema::hasColumn('payroll', 'tipsAmount')) {
                    $table->decimal('tipsAmount', 14, 2)->default(0)->after('holidayHours');
                }
                if (!Schema::hasColumn('payroll', 'bonusAmount')) {
                    $table->decimal('bonusAmount', 14, 2)->default(0)->after('tipsAmount');
                }
                if (!Schema::hasColumn('payroll', 'taxableGross')) {
                    $table->decimal('taxableGross', 14, 2)->default(0)->after('grossSalary');
                }
                if (!Schema::hasColumn('payroll', 'ssWages')) {
                    $table->decimal('ssWages', 14, 2)->default(0)->after('taxableGross');
                }
                if (!Schema::hasColumn('payroll', 'employerCostTotal')) {
                    $table->decimal('employerCostTotal', 14, 2)->default(0)->after('netSalary');
                }
            });
        }

        Schema::create('payroll_earning_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payroll_run_id');
            $table->foreignUuid('employeeId')->constrained('employee')->cascadeOnDelete();
            $table->uuid('payroll_id')->nullable();
            $table->foreignId('departmentId')->nullable()->constrained('department')->nullOnDelete();
            $table->foreignId('payroll_earning_code_id')->constrained('payroll_earning_code')->restrictOnDelete();
            $table->decimal('hours', 8, 2)->nullable();
            $table->decimal('rate', 12, 4)->nullable();
            $table->decimal('amount', 14, 2);
            $table->foreignUuid('accountId')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_ss_subject')->default(true);
            $table->string('source_type', 32)->default('MANUAL');
            $table->uuid('source_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('payroll_run_id')
                ->references('id')->on('payroll_runs')
                ->cascadeOnDelete();
            $table->foreign('payroll_id')
                ->references('id')->on('payroll')
                ->nullOnDelete();

            $table->index(['payroll_run_id', 'departmentId']);
            $table->index(['payroll_run_id', 'employeeId']);
        });

        if (Schema::hasTable('historical_employee_allowance')) {
            Schema::table('historical_employee_allowance', function (Blueprint $table) {
                if (!Schema::hasColumn('historical_employee_allowance', 'departmentId')) {
                    $table->foreignId('departmentId')->nullable()->after('employeeId')->constrained('department')->nullOnDelete();
                }
                if (!Schema::hasColumn('historical_employee_allowance', 'taxableAmount')) {
                    $table->decimal('taxableAmount', 14, 2)->nullable()->after('amount');
                }
                if (!Schema::hasColumn('historical_employee_allowance', 'ssSubjectAmount')) {
                    $table->decimal('ssSubjectAmount', 14, 2)->nullable()->after('taxableAmount');
                }
            });
        }

        if (Schema::hasTable('historical_employee_deduction')) {
            Schema::table('historical_employee_deduction', function (Blueprint $table) {
                if (!Schema::hasColumn('historical_employee_deduction', 'departmentId')) {
                    $table->foreignId('departmentId')->nullable()->after('employeeId')->constrained('department')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('payroll_contributions') && !Schema::hasColumn('payroll_contributions', 'payroll_run_id')) {
            Schema::table('payroll_contributions', function (Blueprint $table) {
                $table->uuid('payroll_run_id')->nullable()->after('id');
                $table->foreign('payroll_run_id')
                    ->references('id')->on('payroll_runs')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('journal_lines') && !Schema::hasColumn('journal_lines', 'departmentId')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->foreignId('departmentId')->nullable()->after('account_id')->constrained('department')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('journal_lines') && Schema::hasColumn('journal_lines', 'departmentId')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->dropForeign(['departmentId']);
                $table->dropColumn('departmentId');
            });
        }

        if (Schema::hasTable('payroll_contributions') && Schema::hasColumn('payroll_contributions', 'payroll_run_id')) {
            Schema::table('payroll_contributions', function (Blueprint $table) {
                $table->dropForeign(['payroll_run_id']);
                $table->dropColumn('payroll_run_id');
            });
        }

        Schema::dropIfExists('payroll_earning_line');
        Schema::dropIfExists('payroll_earning_code');

        if (Schema::hasTable('payroll')) {
            Schema::table('payroll', function (Blueprint $table) {
                foreach ([
                    'payroll_run_id', 'departmentId', 'holidayHours', 'tipsAmount', 'bonusAmount',
                    'taxableGross', 'ssWages', 'employerCostTotal',
                ] as $column) {
                    if (Schema::hasColumn('payroll', $column)) {
                        if (in_array($column, ['payroll_run_id', 'departmentId'], true)) {
                            $table->dropForeign([$column === 'departmentId' ? 'departmentId' : 'payroll_run_id']);
                        }
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('deduction_type') && Schema::hasColumn('deduction_type', 'category')) {
            Schema::table('deduction_type', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
